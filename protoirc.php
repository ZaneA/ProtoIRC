<?php
//
// ProtoIRC framework
// Author: Zane Ashby
//

class ProtoIRC {
        var $host, $port, $nick, $last, $socket, $handlers = array(), $bhandlers = array('command');

	function ProtoIRC($host, $port, $nick, $conn_func) {
		$this->host = $host;
		$this->port = $port;
		$this->nick = $nick;

                // Built in handlers
                $this->in('/^.* (?:422|376)/', $conn_func);

                $this->in('/^PING (.*)/', function ($irc, $args) {
                        $irc->send("PONG {$args}");
                });

                $this->in('/^:(.*)!~.* PRIVMSG (.*) :/', function ($irc, $nick, $dest) {
                        $irc->last = ($dest == $irc->nick) ? $nick : $dest;
                });

                $this->out('/^(?:JOIN) (#.*)/', function ($irc, $dest) {
                        $irc->last = $dest;
                });

                $this->out('/^(?:PRIVMSG) (.*) :/', function ($irc, $dest) {
                        $irc->last = $dest;
                });

                $this->out('/^NICK (.*)/', function ($irc, $nick) {
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

                        if (empty($dest) || empty($msg)) {
                                return;
                        }

                        if (func_num_args() == 3) {
                                $color = func_get_arg(2);
                        } else {
                                $color = false;
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
                        break;

                default:
                        return;
                }

                if ($this->call('out', $data) === false) return;

		if ($this->socket) {
			fwrite($this->socket, "{$data}\r\n");
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
			$this->handlers[$type][$regex][] = $function;

                        // Sort by regex length, rough approximation of regex
                        // "wideness", since we want catch-all's to come last.
                        // This can probably be improved..
                        uksort($this->handlers[$type], function ($a, $b) {
                                return (strlen($b) - strlen($a));
                        });

                        return count($this->handlers[$type][$regex]) - 1; // Key
                } else {
                        unset($this->handlers[$type][$regex][$function]);
                }
	}

        // Simple bind shortcut using overloading
        function __call($func, $args) {
                if (sizeof($args) == 1 || (!is_callable($args[1]) && !is_numeric($args[1]))) {
                        array_unshift($args, $func);
                        return call_user_func_array(array($this, 'call'), $args);
                } else {
                        array_unshift($args, $func);
                        return call_user_func_array(array($this, 'bind'), $args);
                }
        }

        function call($type, $data) {
                if (!isset($this->handlers[$type])) return;

                foreach ($this->handlers[$type] as $regex => $handlers) {
                        if (preg_match($regex, $data, $matches) == 1) {
                                foreach ($handlers as $func) {
                                        array_shift($matches);

                                        // Add additional arguments
                                        for ($i = func_num_args() - 1; $i > 1; $i--) {
                                                array_unshift($matches, func_get_arg($i));
                                        }

                                        array_unshift($matches, $this);

                                        $r = call_user_func_array($func, $matches);
                                        if (!empty($r)) return $r;
                                        if (in_array($type, $this->bhandlers)) return;
                                }
                        }
                }
        }

	function go() {
                while (true) {
                        $this->socket = fsockopen($this->host, $this->port);

                        if (!$this->socket) {
                                sleep(60); // Retry after a minute
                                break;
                        }

                        $this->send("NICK {$this->nick}");
                        $this->send("USER {$this->nick} * * :{$this->nick}");

                        do {
                                $r = array($this->socket, STDIN);

                                if (stream_select($r, $w = null, $x = null, 1)) {
                                        foreach ($r as $stream) {
                                                $buffer = trim(fgets($stream, 1024), "\r\n");

                                                if (stream_is_local($stream)) {
                                                        $this->call('command', $buffer);
                                                } else {
                                                        $this->call('in', $buffer);
                                                }
                                        }
                                }

                                // Clean up any waiting children
                                pcntl_waitpid(-1, $status, WNOHANG);

                                if (!isset($this->handlers['timer'])) continue;

                                $now = time();

                                foreach ($this->handlers['timer'] as $time => $handlers) {
                                        if (($now % $time) == 0) {
                                                foreach ($handlers as $func) {
                                                        call_user_func($func, $this);
                                                }
                                        }
                                }
                        } while (!feof($this->socket));

                        sleep(30); // Reconnect after half a minute
                }
        }
}
