<?php
//
// ProtoIRC framework
// Author: Zane Ashby
//

class ProtoIRC {
        var $host, $port, $nick, $last, $socket, $lastmsg;
        var $handlers = array(), $bhandlers = array('command');

        function ProtoIRC($host, $port, $nick, $conn_func) {
                $this->host = $host;
                $this->port = $port;
                $this->nick = $nick;

                // Built in handlers
                $this->in('/^.* (?:422|376)(?#builtin)/', $conn_func);

                $this->in('/^PING (.*)(?#builtin)/', function ($irc, $args) {
                        $irc->send("PONG {$args}");
                });

                $this->in('/^:(.*)!~.* PRIVMSG (.*) :(?#builtin)/', function ($irc, $nick, $dest) {
                        $irc->last = ($dest == $irc->nick) ? $nick : $dest;
                });

                $this->out('/^(?:JOIN) (#.*)(?#builtin)/', function ($irc, $dest) {
                        $irc->last = $dest;
                });

                $this->out('/^(?:PRIVMSG) (.*) :(?#builtin)/', function ($irc, $dest) {
                        $irc->last = $dest;
                });

                $this->out('/^NICK (.*)(?#builtin)/', function ($irc, $nick) {
                        $irc->nick = $nick;
                });
        }

        function ircColor($color = 'default') {
                $colors = array('lt.white', 'black', 'blue', 'green', 'lt.red', 'red', 'purple', 'yellow', 'lt.yellow', 'lt.green', 'cyan', 'lt.cyan', 'lt.blue', 'lt.purple', 'lt.black', 'white');

                if (($color = array_search($color, $colors)) !== false) {
                        return chr(0x03).sprintf('%02s', $color);
                } else {
                        return chr(0x03);
                }
        }

        function termColor($color = 'default') {
                if (substr($color, 0, 3) == 'lt.') {
                        $color = substr($color, 3);
                        $bold = 1;
                } else {
                        $bold = 0;
                }

                $colors = array('black', 'red', 'green', 'yellow', 'blue', 'purple', 'cyan', 'white');

                if (($color = array_search($color, $colors)) !== false) {
                        return "\033[{$bold};".(30 + $color)."m";
                } else {
                        return "\033[0m";
                }
        }

        // TODO: Rename this function to something shorter..
        function termEcho($line, $color = 'default') {
                echo $this->termColor($color).$line.$this->termColor();
        }

        // FIXME: This function is ugly
        function send() {
                switch (func_num_args()) {
                case 1:
                        $data = func_get_arg(0);
                        break;

                case 2:
                case 3:
                        $dest = func_get_arg(0);
                        $msg = func_get_arg(1);

                        if (empty($dest) || empty($msg)) {
                                return;
                        }

                        if (func_num_args() == 3) {
                                $color = func_get_arg(2);
                        } else {
                                $color = false;
                        }


                        // Print stuff containing newlines as expected..

                        if (!is_array($msg) && strpos($msg, "\n") !== false) {
                                $msg = explode("\n", $msg);
                        }

                        if (is_array($msg)) {
                                foreach ($msg as $line) {
                                        $this->send($dest, $line, $color);
                                }

                                return;
                        }

                        if ($color) {
                                $data = 'PRIVMSG '.$dest.' :'.$this->ircColor($color).$msg.$this->ircColor();
                        } else {
                                $data = 'PRIVMSG '.$dest.' :'.$msg;
                        }
                        break;

                default:
                        return;
                }

                if ($this->out($data) === false) return;

                if ($this->socket) {
                        fwrite($this->socket, "{$data}\r\n");
                        usleep(200000);
                }
        }

        // This function is too simple, it feels like cheating.
        // TODO: Add some form of join command.. :)
        function async($function) {
                if (pcntl_fork() == 0) { // In child process now
                        call_user_func($function, $this);
                        exit();
                }
        }

        function bind($type, $regex, $function) {
                if (is_callable($function)) {
                        $this->handlers[$type][$regex] = $function;

                        // Sort by regex length, rough approximation of regex
                        // "wideness", since we want catch-all's to come last.
                        // This can probably be improved..
                        uksort($this->handlers[$type], function ($a, $b) {
                                return (strlen($b) - strlen($a));
                        });
                } else {
                        unset($this->handlers[$type][$regex]);
                }
        }

        // Simple bind/call shortcut using overloading
        function __call($type, $args) {
                array_unshift($args, $type);

                if (count($args) == 2 || !is_callable($args[2])) {
                        return call_user_func_array(array($this, 'call'), $args);
                } else {
                        return call_user_func_array(array($this, 'bind'), $args);
                }
        }

        function call($type, $data) {
                if (!isset($this->handlers[$type])) return;

                foreach ($this->handlers[$type] as $regex => $func) {
                        if (preg_match($regex, $data, $matches) == 1) {
                                array_shift($matches); // Remove full match from array

                                // Add additional arguments
                                for ($i = func_num_args() - 1; $i > 1; $i--) {
                                        array_unshift($matches, func_get_arg($i));
                                }

                                array_unshift($matches, $this); // Add $irc parameter

                                $r = call_user_func_array($func, $matches);

                                if (!empty($r)) return $r;

                                // By default 'command' handlers should return as soon as
                                // a regex has matched. More intuitive this way.
                                if (in_array($type, $this->bhandlers)) return;
                        }
                }
        }

        function go() {
                while (true) {
                        $this->socket = @fsockopen($this->host, $this->port);

                        if (!$this->socket) {
                                sleep(60); // Retry after a minute
                                continue;
                        }

                        $this->lastmsg = time();

                        $this->send("NICK {$this->nick}");
                        $this->send("USER {$this->nick} * * :{$this->nick}");

                        do {
                                $r = array($this->socket, STDIN);

                                if (stream_select($r, $w = null, $x = null, 1)) {
                                        foreach ($r as $stream) {
                                                $buffer = trim(fgets($stream, 1024), "\r\n");

                                                if (stream_is_local($stream)) {
                                                        $this->command($buffer);
                                                } else {
                                                        $this->lastmsg = time();
                                                        $this->in($buffer);
                                                }
                                        }
                                }

                                // Clean up any waiting child processes
                                pcntl_waitpid(-1, $status, WNOHANG);

                                // The timer bind is a special case that is handled here
                                if (isset($this->handlers['timer'])) {
                                        $now = time();

                                        foreach ($this->handlers['timer'] as $time => $func) {
                                                if (($now % $time) == 0) {
                                                        call_user_func($func, $this);
                                                }
                                        }
                                }

                                // Have we lost connection? (No activity for 5 minutes)
                                if (time() > $this->lastmsg + (60 * 5)) {
                                        fclose($this->socket);
                                        $this->socket = null;
                                }
                        } while ($this->socket && !feof($this->socket));

                        sleep(30); // Wait half a minute and then reconnect
                }
        }
}
