<?php
//
// ProtoIRC framework
// Author: Zane Ashby
//

define('COMMAND', 0);
define('IN', 1);
define('OUT', 2);
define('TIMER', 3);

class ProtoIRC {
        var $host, $port, $nick, $lastChannel, $socket, $handlers = array();

	function ProtoIRC($host, $port, $nick, $conn_func) {
		$this->host = $host;
		$this->port = $port;
		$this->nick = $nick;


                // Built in handlers for PING/PONG and Connect (376)
                $this->in('/^PING (.*)/', function ($irc, $args) {
                        $irc->send("PONG {$args}");
                });

                $this->in('/^.* 376/', $conn_func);
        }

	function ircColor($color = 'default') {
		$colors = Array('white', 'black', 'blue', 'green', 'red', 'brown', 'purple', 'orange', 'yellow', 'lt.green', 'teal', 'lt.cyan', 'lt.blue', 'pink', 'grey', 'lt.grey');

		$color = array_search($color, $colors);

		if ($color !== false) {
			return chr(0x03).$color;
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

                $colors = Array('black', 'red', 'green', 'yellow', 'blue', 'purple', 'cyan', 'white');

                $color = array_search($color, $colors);

                if ($color !== false) {
                        return "\033[{$bold};".(30 + $color)."m";
                } else {
                        return "\033[0m";
                }
        }

        function termEcho($line, $color = 'default') {
                echo $this->termColor($color).$line.$this->termColor();
        }

	function send() {
                switch (func_num_args()) {
                case 1:
                        $data = func_get_arg(0);
                        break;

                case 2:
                case 3:
                        $dest = func_get_arg(0);
                        $msg = func_get_arg(1);

                        if (func_num_args() == 3) {
                                $color = func_get_arg(2);
                        } else {
                                $color = false;
                        }

                        if (empty($dest) || empty($msg)) {
                                return;
                        }

                        // FIXME UGLY!
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

                        $this->lastChannel = $dest;
                        break;

                default:
                        return;
                }

                if ($this->call(OUT, $data)) return;

		if ($this->socket) {
			fwrite($this->socket, $data."\n\r");
                        usleep(200000);
		}
	}

        function async($function) {
                if (pcntl_fork() == 0) {
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

        // Simple bind shortcut using overloading
        function __call($func, $args) {
                array_unshift($args, constant(strtoupper($func)));
                return call_user_func_array(array($this, 'bind'), $args);
        }

        // Shortcut for send
        function __invoke() {
                return call_user_func_array(array($this, 'send'), func_get_args());
        }

        function call($type, $data) {
                if (!isset($this->handlers[$type])) return;

                foreach ($this->handlers[$type] as $regex => $func) {
                        if (preg_match($regex, $data, $matches) == 1) {
                                array_shift($matches);
                                array_unshift($matches, $this);

                                return call_user_func_array($func, $matches);
                        }
                }
        }

	function go() {
                $this->socket = fsockopen($this->host, $this->port);

                if (!$this->socket) return;

                $this->send("NICK {$this->nick}");
                $this->send("USER {$this->nick} * * :{$this->nick}");

                while(!feof($this->socket)) {
                        $r = array($this->socket, STDIN);

                        if (stream_select($r, $w = null, $x = null, 1)) {
                                foreach ($r as $stream) {
                                        $buffer = trim(fgets($stream, 1024), "\r\n");

                                        if (stream_is_local($stream)) {
                                                $this->call(COMMAND, $buffer);
                                        } else {
                                                $this->call(IN, $buffer);
                                        }
                                }
                        }

                        // Clean up any waiting children
                        pcntl_waitpid(-1, $status, WNOHANG);

                        if (!isset($this->handlers[TIMER])) continue;

                        $now = time();

                        foreach ($this->handlers[TIMER] as $time => $func) {
                                if (($now % $time) == 0) {
                                        call_user_func($func, $this);
                                }
                        }
                }
	}
}
