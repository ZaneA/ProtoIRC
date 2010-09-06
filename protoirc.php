<?php
//
// ProtoIRC framework
// Author: Zane Ashby
//

function ProtoIRC($conn_string, $conn_func = null) { return new ProtoIRC($conn_string, $conn_func); }

class ProtoIRC {
        var $host, $port, $nick, $last, $channels, $socket, $lastmsg, $child;
        var $handlers = array(), $bhandlers = array('stdin');

        function ProtoIRC($conn_string, $conn_func = null) {
                $this->nick = parse_url($conn_string, PHP_URL_USER) ?: 'ProtoBot';
                $this->host = parse_url($conn_string, PHP_URL_SCHEME) . parse_url($conn_string, PHP_URL_HOST) ?: '127.0.0.1';
                $this->port = parse_url($conn_string, PHP_URL_PORT) ?: '6667';
                $channels = trim(parse_url($conn_string, PHP_URL_PATH), '/');
                $auth = parse_url($conn_string, PHP_URL_PASS);
                $key = parse_url($conn_string, PHP_URL_FRAGMENT);

                // Built in handlers
 
                if (!empty($channels)) {
                        $this->in(
                                '/^.* (?:422|376)(?#builtincb)/',
                                function ($irc) use ($conn_string, $channels, $auth, $key) {
                                        if ($auth)
                                                $irc->send('NickServ', "IDENTIFY {$auth}");

                                        foreach (explode(',', $channels) as $channel)
                                                $irc->send("JOIN #{$channel} {$key}");
                                }
                        );
                }

                if (is_callable($conn_func))
                        $this->in('/^.* (?:422|376)(?#usercb)/', $conn_func);

                $this->in(
                        '/^PING (.*)(?#builtin)/',
                        function ($irc, $args) {
                                $irc->send("PONG {$args}");
                        }
                );

                $this->in(
                        '/^:(.*?)!~.* PRIVMSG (.*) :(.*)(?#builtin)/',
                        function ($irc, $nick, $dest, $msg) {
                                $irc->last = ($dest == $irc->nick) ? $nick : $dest;
                                $irc->msg($msg, $nick, $dest);
                        }
                );

                $this->in(
                        '/^:(.*?)!~.*? JOIN :(.*)(?#builtin)/',
                        function ($irc, $nick, $channel) {
                                if ($nick == $irc->nick)
                                        $irc->channels[] = $channel;
                        }
                );

                $this->in(
                        '/^:(.*?)!~.*? PART (.*)(?#builtin)/',
                        function ($irc, $nick, $channel) {
                                if ($nick == $irc->nick && ($key = array_search($channel, $irc->channels)) !== false)
                                        unset($irc->channels[$key]);
                        }
                );

                $this->out(
                        array(
                                '/^(?:JOIN) (#.*)(?#builtin)/',
                                '/^(?:PRIVMSG|NOTICE) (.*) :(?#builtin)/',
                                '/^NICK (.*)(?#builtin)/',
                        ),
                        function ($irc, $dest) {
                                $irc->last = $dest;
                        }
                );
        }

        function ircColor($color = 'default') {
                $colors = explode(' ', 'lt.white black blue green lt.red red purple yellow lt.yellow lt.green cyan lt.cyan lt.blue lt.purple lt.black white');

                if (($color = array_search($color, $colors)) !== false) {
                        return chr(0x03) . sprintf('%02s', $color);
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

                $colors = explode(' ', 'black red green yellow blue purple cyan white');

                if (($color = array_search($color, $colors)) !== false) {
                        return "\033[{$bold};" . (30 + $color) . "m";
                } else {
                        return "\033[0m";
                }
        }

        function stdout($line, $color = 'default') {
                echo $this->termColor($color) . $line . $this->termColor();
        }

        // FIXME: This function is ugly
        function send() {
                if (!$this->socket)
                        return;
                
                switch (func_num_args()) {
                case 1:
                        $data = func_get_arg(0);
                        break;

                case 2:
                case 3:
                        $dest = func_get_arg(0);
                        $msg = func_get_arg(1);

                        if (empty($dest) || empty($msg))
                                return;

                        $color = (func_num_args() == 3) ? func_get_arg(2) : false;

                        // Send to multiple destinations..

                        if (is_array($dest)) {
                                foreach ($dest as $sdest)
                                        $this->send($sdest, $msg, $color);

                                return;
                        }

                        // Print stuff containing newlines as expected..

                        if (!is_array($msg) && strpos($msg, "\n") !== false)
                                $msg = explode("\n", $msg);

                        if (is_array($msg)) {
                                foreach ($msg as $line)
                                        $this->send($dest, $line, $color);

                                return;
                        }

                        if ($color) {
                                $data = "PRIVMSG {$dest} :" . $this->ircColor($color) . $msg . $this->ircColor();
                        } else {
                                $data = "PRIVMSG {$dest} :{$msg}";
                        }
                        break;

                default:
                        return;
                }

                if ($this->out($data) === false)
                        return;

                fwrite($this->socket, "{$data}\r\n");
                usleep(200000);

                return $this;
        }

        function async($function) {
                socket_create_pair(AF_UNIX, SOCK_STREAM, 0, $sockets); // IPC

                list($parent, $child) = $sockets;

                if (($pid = pcntl_fork()) == 0) { // In child process now
                        socket_close($child);
                        socket_write($parent, serialize(call_user_func($function, $this)));
                        socket_close($parent);

                        exit;
                }

                socket_close($parent);

                $this->child[$pid] = $child;
                
                return $pid;
        }

        // Will block if child is still alive, otherwise will directly return output
        function wait($pid) {
                if (isset($this->child[$pid])) {
                        pcntl_waitpid($pid, $status);

                        if (is_resource($this->child[$pid])) {
                                $output = unserialize(socket_read($this->child[$pid], 4096));
                                socket_close($this->child[$pid]);
                        } else {
                                $output = $this->child[$pid];
                        }

                        unset($this->child[$pid]);

                        return $output;
                }
        }

        function bind($type, $regex, $function) {
                if (is_callable($function)) {
                        if (!is_array($regex))
                                $regex = array($regex);

                        foreach ($regex as $_regex) {
                                $this->handlers[$type][$_regex] = $function;

                                // Sort by regex length, rough approximation of regex
                                // "wideness", since we want catch-all's to come last.
                                // This can probably be improved..
                                uksort($this->handlers[$type], function ($a, $b) {
                                        return (strlen($b) - strlen($a));
                                });
                        }
                } else {
                        unset($this->handlers[$type][$regex]);
                }

                return $this;
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
                if (!isset($this->handlers[$type]))
                        return $this->send(strtoupper($type) . " {$data}");

                foreach ($this->handlers[$type] as $regex => $func) {
                        if (preg_match($regex, $data, $matches)) {
                                array_shift($matches); // Remove full match from array

                                // Add additional arguments
                                for ($i = func_num_args() - 1; $i > 1; $i--)
                                        array_unshift($matches, func_get_arg($i));

                                array_unshift($matches, $this); // Add $irc parameter

                                $r = call_user_func_array($func, $matches);

                                if (!empty($r))
                                        return $r;

                                // By default 'stdin' handlers should return as soon as
                                // a regex has matched. More intuitive this way.
                                if (in_array($type, $this->bhandlers))
                                        return $this;
                        }
                }

                return $this;
        }

        function go() {
                while (true) {
                        if (!($this->socket = @fsockopen($this->host, $this->port)))
                                // Keep reconnecting until it succeeds
                                continue;

                        $this->lastmsg = time();

                        $this->send("NICK {$this->nick}");
                        $this->send("USER {$this->nick} * * :{$this->nick}");

                        do {
                                $r = array($this->socket, STDIN);

                                if (stream_select($r, $w = null, $x = null, 1)) {
                                        foreach ($r as $stream) {
                                                $buffer = trim(fgets($stream, 4096), "\r\n");

                                                if (stream_is_local($stream)) {
                                                        $this->stdin($buffer);
                                                } else {
                                                        $this->lastmsg = time();
                                                        $this->in($buffer);
                                                }
                                        }
                                }

                                // Garbage Collect, Clean up any waiting child processes
                                if (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                                        $output = unserialize(socket_read($this->child[$pid], 4096));
                                        socket_close($this->child[$pid]);
                                        if (!empty($output)) {
                                                $this->child[$pid] = $output;
                                        } else {
                                                unset($this->child[$pid]);
                                        }
                                }

                                // The timer bind is a special case that is handled here
                                if (isset($this->handlers['timer'])) {
                                        $now = time();

                                        foreach ($this->handlers['timer'] as $time => $func)
                                                if (($now % $time) == 0)
                                                        call_user_func($func, $this);
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
