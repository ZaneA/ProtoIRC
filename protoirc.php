<?php
/**
 * ProtoIRC IRC bot framework.
 *
 * @author Zane Ashby <zane.a@demonastery.org>
 */

/**
 * ProtoIRC entry-point.
 *
 * @param string   $conn_string A string describing the connection to attempt.
 * @param callable $conn_func An optional callback function that is called after the connection is ready.
 *
 * @return ProtoIRC A new instance of the ProtoIRC class, to allow easy chaining.
 */
function ProtoIRC($conn_string, $conn_func = null) {
  return new ProtoIRC($conn_string, $conn_func);
}

/**
 * ProtoIRC class.
 */
class ProtoIRC {
  /** @var string Hostname of the IRC server. */
  var $host;

  /** @var int Port of the IRC server. */
  var $port;

  /** @var string Nickname. */
  var $nick;

  /** @var string Last destination that a message was sent to. Either a nickname or a channel. */
  var $last;

  /** @var array List of channels to connect to. */
  var $channels;

  /** @var resource IRC socket. */
  var $socket;

  /** @var array Array of child PID's and their return values (if any). */
  var $child;

  /** @var array Array of event handlers. */
  var $handlers = array();

  /** @var array Array of event handlers that should return early (on first match). */
  var $bhandlers = array('stdin');

  /** @var object Object containing color values. */
  var $ansi;

  /**
   * Constructor.
   *
   * @param string   $conn_string A string describing the connection to attempt.
   * @param callable $conn_func A callback function that is called after the connection is ready.
   */
  function __construct($conn_string, $conn_func = null) {
    $defaults = array(
      'user' => 'ProtoBot',
      'host' => '127.0.0.1',
      'port' => '6667',
      'path' => '',
      'pass' => false,
      'fragment' => '',
    );

    $url = (object)array_merge($defaults, parse_url($conn_string));

    $this->nick = $url->user;
    $this->host = $url->host;
    $this->port = $url->port;
    $channels = trim($url->path, '/');
    $auth = $url->pass;
    $key = $url->fragment;

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
      '/^:(.*?)!~?.* PRIVMSG (.*?) :(.*)(?#builtin)/',
      function ($irc, $nick, $dest, $msg) {
        $irc->last = ($dest == $irc->nick) ? $nick : $dest;
        $irc->msg($msg, $nick, $dest);
      }
    );

    $this->in(
      '/^:(.*?)!~?.*? JOIN :(.*)(?#builtin)/',
      function ($irc, $nick, $channel) {
        if ($nick == $irc->nick)
          $irc->channels[] = $channel;
      }
    );

    $this->in(
      '/^:(.*?)!~?.*? PART (.*)(?#builtin)/',
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

  /**
   * Generate an object containing IRC colors.
   *
   * @return object An object with color names as keys.
   */
  function genIRCColors() {
    $colors = array_flip(explode(' ', '_white black blue green _red red purple yellow _yellow _green cyan _cyan _blue _purple _black white'));

    foreach ($colors as &$v)
      $v = sprintf("\3%02s", $v);

    return (object)($colors + array('default' => "\3"));
  }

  /**
   * Generate an object containing ANSI colors (for use on terminals).
   *
   * @return object An object with color names as keys.
   */
  function genANSIColors() {
    $colors = array_flip(explode(' ', 'black red green yellow blue purple cyan white'));
    foreach ($colors as &$v)
      $v = "\033[0;" . (30 + $v) . "m";

    $bcolors = array_flip(explode(' ', '_black _red _green _yellow _blue _purple _cyan _white'));
    foreach ($bcolors as &$v)
      $v = "\033[1;" . (30 + $v) . "m";

    return (object)($colors + $bcolors + array('default' => "\033[0m"));
  }

  /**
   * Write a line to STDOUT.
   *
   * @param string Some text to output to STDOUT.
   * @param string The name of a color to output this text in.
   */
  function stdout($line, $color = 'default') {
    echo "{$this->ansi->$color}{$line}{$this->ansi->default}";
  }

  /**
   * Send "stuff" to the IRC socket, in a few different ways.
   *
   * This method does different things depending on the number of arguments that it receives.
   *
   * @param string $data|$dest Either a valid string to send to an IRC server, or a destination.
   * @param string $msg Message to send to destination. Optional if sending data directly.
   * @param string $color Color to use for message. Optional.
   *
   * @return \ProtoIRC The instance.
   */
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

  /**
   * Do something asynchronously.
   *
   * This neat little method sets up a UNIX socket for IPC, and then forks the current process.
   * The child is then able to talk to the parent, by way of a return value.
   *
   * @param callable A callback that is running in the forked instance. Anything returned will be captured by the main process.
   *
   * @return int The PID of the child process, for keeping track of your children.
   */
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

  /**
   * Read the value returned by a child process.
   *
   * @param int $pid The PID of the child process to read.
   *
   * @return mixed The stored value is returned if the child has exited, otherwise false. 
   */
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

  /**
   * Wait for a child process to exit and return its value.
   *
   * Similar to the read function but will wait for the child to exit if necessary.
   *
   * @see \ProtoIRC::read()
   *
   * @param int The PID of the child process to read.
   *
   * @return mixed The returned value of the child process.
   */
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

  /**
   * Bind a callback to an event category, matched by regular expression.
   *
   * This is essentially a small event system used throughout the framework for dispatching methods.
   * This can be thought of as "on". In fact it may end up being renamed to that effect.
   *
   * @param string   $type     The event category of event this is related to (such as "stdin", "out", and "in").
   * @param string   $regex    A regular expression that is matched against the event.
   * @param callable $function The callback that is called upon a successfull match.
   *
   * @return \ProtoIRC The instance.
   */
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

  /**
   * Magic method invoked when an unknown method is called.
   *
   * This is used as a shortcut for the event system (bind/call).
   *
   * @param string $type The event category, as seen in ProtoIRC::bind().
   * @param array  $args The provided arguments.
   *
   * @return mixed Returns what the call/bind methods do.
   */
  function __call($type, $args) {
    array_unshift($args, $type);

    if (count($args) == 2 || !is_callable($args[2])) {
      return call_user_func_array(array($this, 'call'), $args);
    } else {
      return call_user_func_array(array($this, 'bind'), $args);
    }
  }

  /**
   * Call an event.
   *
   * This can be thought of as "emit". In fact it may end up being renamed to that effect.
   *
   * @param string $type The event category.
   * @param string $data The data that is matched against regular expressions.
   *
   * @return mixed Returns different values based on the matches.
   *   Of note, when there are no handlers registered for a type, this method will send to IRC directly.
   *   For example, a call of `$protoirc->notice($data)` is transformed into an IRC NOTICE.
   */
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

  /**
   * Start the bot.
   *
   * This function handles connecting to IRC and acting as a mainloop.
   */
  function go() {
    while (true) {
      if (!($this->socket = @fsockopen($this->host, $this->port)))
        continue; // Keep reconnecting until it succeeds

      $lastmsg = time();

      $this->nick($this->nick);
      $this->user("{$this->nick} * * :{$this->nick}");

      do {
        $r = array($this->socket, STDIN);

        $w = null;
        $x = null;
        if (stream_select($r, $w, $x, 1)) {
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
