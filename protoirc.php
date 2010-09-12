<?php
//
// ProtoIRC framework
// Author: Zane Ashby
//

function ProtoIRC($conn_string, $conn_func = null) { return new ProtoIRC($conn_string, $conn_func); }

class ProtoIRC {
        var $host, $port, $nick, $last, $channels, $socket, $child,
            $handlers = array(), $bhandlers = array('stdin'), $ansi;

        function __construct($conn_string, $conn_func = null) {
                $url = (object)parse_url($conn_string);

                @$this->nick = $url->user ?: 'ProtoBot';
                @$this->host = $url->scheme . $url->host ?: '127.0.0.1';
                @$this->port = $url->port ?: '6667';
                @$channels = trim($url->path, '/');
                @$auth = $url->pass;
                @$key = $url->fragment;

                foreach ($this->genIRCColors() as $color => $value)
                        $this->$color = $value;

                $this->ansi = $this->genANSIColors();

                // Set up some built in handlers for various messages
 
                if (!empty($channels)) {
                        $this->in(
                                '/^.* (?:422|376)(?#builtincb)/',
                                function ($irc) use ($channels, $auth, $key) {
                                        if ($auth)
                                                $irc->send('NickServ', "IDENTIFY {$auth}");

                                        foreach (explode(',', $channels) as $channel)
                                                $irc->join("#{$channel} {$key}");
                                }
                        );
                }

                $this->in('/^.* (?:422|376)(?#usercb)/', $conn_func);

                $this->in(
                        '/^PING (.*)(?#builtin)/',
                        function ($irc, $args) {
                                $irc->pong($args);
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


        function genIRCColors() {
                $colors = array_flip(explode(' ', '_white black blue green _red red purple yellow _yellow _green cyan _cyan _blue _purple _black white'));

                foreach ($colors as &$v)
                        $v = sprintf("\3%02s", $v);

                return (object)($colors + array('default' => "\3"));
        }

        function genANSIColors() {
                $colors = array_flip(explode(' ', 'black red green yellow blue purple cyan white'));
                foreach ($colors as &$v)
                        $v = "\033[0;" . (30 + $v) . "m";

                $bcolors = array_flip(explode(' ', '_black _red _green _yellow _blue _purple _cyan _white'));
                foreach ($bcolors as &$v)
                        $v = "\033[1;" . (30 + $v) . "m";

                return (object)($colors + $bcolors + array('default' => "\033[0m"));
        }

        function stdout($line, $color = 'default') {
                echo "{$this->ansi->$color}{$line}{$this->ansi->default}";
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
                                $data = "PRIVMSG {$dest} :{$this->$color}{$msg}{$this->default}";
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

        function read($pid) {
                if (isset($this->child[$pid])) {
                        if (!is_resource($this->child[$pid])) {
                                $output = $this->child[$pid];
                                unset($this->child[$pid]);
                                return $output;
                        }
                }

                return false;
        }

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
                        foreach ((array)$regex as $_regex) {
                                $this->handlers[$type][$_regex] = $function;

                                // Sort by regex length, rough approximation of regex
                                // "wideness", since we want catch-all's to come last.
                                uksort($this->handlers[$type], function ($a, $b) {
                                        return (strlen($b) - strlen($a));
                                });
                        }
                }

                return $this;
        }

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

                                for ($i = func_num_args() - 1; $i > 1; $i--)
                                        array_unshift($matches, func_get_arg($i));

                                array_unshift($matches, $this); // Add $irc parameter

                                if (($r = call_user_func_array($func, $matches)))
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
                                continue; // Keep reconnecting until it succeeds

                        $lastmsg = time();

                        $this->nick($this->nick);
                        $this->user("{$this->nick} * * :{$this->nick}");

                        do {
                                $r = array($this->socket, STDIN);

                                if (stream_select($r, $w = null, $x = null, 1)) {
                                        foreach ($r as $stream) {
                                                $buffer = trim(fgets($stream, 4096));

                                                if (stream_is_local($stream)) {
                                                        $this->stdin($buffer);
                                                } else {
                                                        $lastmsg = time();
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

                                if (isset($this->handlers['timer'])) {
                                        $now = time();

                                        foreach ($this->handlers['timer'] as $time => $func)
                                                if (($now % $time) == 0)
                                                        $func($this);
                                }

                                if (time() > $lastmsg + (60 * 5)) { // No activity for 5 minutes?
                                        fclose($this->socket);
                                        $this->socket = null;
                                }
                        } while ($this->socket && !feof($this->socket));

                        sleep(30); // Wait half a minute before reconnecting
                }
        }
}
