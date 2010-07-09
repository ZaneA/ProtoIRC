<?php
//
// ProtoIRC framework
// Author: Zane Ashby
//

define('COMMAND', 0);
define('IRC_IN', 1);
define('IRC_OUT', 2);
define('TIMER', 3);

class ProtoIRC {
        var $host, $port, $nick, $lastChannel, $socket, $handlers = array();

	function ProtoIRC($host, $port, $nick, $conn_func) {
		$this->host = $host;
		$this->port = $port;
		$this->nick = $nick;


                // Built in handlers for PING/PONG and Connect (376)
                $this->bind(IRC_IN, '/^PING (.*)/', function ($irc, $args, $line) {
                        $irc->send("PONG {$args[0]}");
                });

                $this->bind(IRC_IN, '/^(.*) 376/', $conn_func);
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

                $colors = Array('black', 'red', 'green', 'brown', 'blue', 'purple', 'cyan', 'white');

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
                        if (func_get_arg(0) == '') {
                                $this->termEcho("Missing a destination!\n", 'red', 1);
                                return;
                        }

                        if (func_num_args() == 3) {
                                $data = 'PRIVMSG '.func_get_arg(0).' :'.$this->ircColor(func_get_arg(2)).func_get_arg(1).$this->ircColor();
                        } else {
                                $data = 'PRIVMSG '.func_get_arg(0).' :'.func_get_arg(1);
                        }

                        $this->lastChannel = func_get_arg(0);
                        break;

                default:
                        return;
                }

                if ($this->call(IRC_OUT, $data)) return;

		if ($this->socket) {
			fwrite($this->socket, $data."\n\r");
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
		}
	}

        function call($type, $data) {
                if (!isset($this->handlers[$type])) return;

                foreach ($this->handlers[$type] as $regex => $func) {
                        if (preg_match($regex, $data, $matches) == 1) {
                                array_shift($matches);

                                return call_user_func($func, $this, $matches, $data);
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
                                                $this->call(IRC_IN, $buffer);
                                        }
                                }
                        }

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
