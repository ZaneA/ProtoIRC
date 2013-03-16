ProtoIRC
===

Rapid IRC client/bot prototyping for PHP >= 5.3

ProtoIRC is a hackable framework that makes it easy to prototype clients and bots for IRC, it works
by handling the connection to the IRC server and letting you write callbacks for everything else.
It makes extensive use of closures and regular expressions.

Usage
---

A "Hello-World" (echo) example is as follows:

```php
<?php
require 'protoirc.php';
 
$irc = new ProtoIRC('NickName@hostname:6667/channel');
 
$irc->in('/^:(.*)!~.* PRIVMSG (.*) :!echo (.*)/', function ($irc, $nick, $channel, $args) {
  // Arguments are self-documenting
  $irc->send($channel, "Echoing '{$args}' for you {$nick}", 'green');
});

// Or use the builtin msg shortcut
$irc->msg('/^!echo (.*)/', function ($irc, $nick, $channel, $args) {
  // Arguments are self-documenting
  $irc->send($channel, "Echoing '{$args}' for you {$nick}", 'green');
});
 
$irc->go();
```

Object chaining is now supported:

```php
<?php
require 'protoirc.php';

ProtoIRC('NickName@hostname:6667/channel')
  ->stdin('/(.*)/', function ($irc, $text) { $irc->send($irc->channels, $text); })
  ->msg('/^!echo (.*)/', function ($irc, $nick, $channel, $args) { $irc->send($channel, "Echoing {$args}"); })
  ->go();
```

See `client.php` for a slightly more in depth example. Use the `runclient.sh` to run it using rlwrap.
See `addons/prototyping.php` for an undocumented prototyping helper..
See `addons/customactions.php` for a custom handler demo..

Available API:
It's probably easier just to look at `protoirc.php`, but here is the basic run down.

```php
<?php
$irc = new ProtoIRC('nickname@hostname:port/channel1,channel2', function ($irc) {
  // Optional connect function
});
 
// Useful variables
$irc->nick;
$irc->last; // Last message destination
$irc->channels; // Currently joined channels
 
// Send a message through the IRC connection
$irc->send('RAW MESSAGE');
$irc->irccmd('args'); // eg. $irc->join('#channel');
$irc->send('#destination', 'message');
$irc->send('#destination', 'message', 'color');

// Embedding Colors
$irc->send('#destination', "{$irc->yellow}Yellow!{$irc->default}");
$irc->stdout("{$irc->ansi->yellow}Yellow!{$irc->ansi->default}");
 
// Echo to the terminal
$irc->stdout('message');
$irc->stdout('message', 'color');
 
// Bind a regex to a function, on either stdin, in, out, or msg
$irc->stdin/in/out/msg('regex', function ($irc, $first_match, $second_match etc) {
  // Runs if the regex matches

  // Run stuff asynchronously
  $irc->async(function ($irc) use ($first_match, $second_match) {
          sleep(5);
          $irc->send('#channel', 'This will print after 5 seconds');
  });

  $irc->send('#channel', 'This will print before the above statement');

  // return false in 'out' callback to stop message being sent
});
 
// Bind a timer
$irc->timer(60, function ($irc) {
  // Runs every minute
  $irc->send($irc->channels, 'Alert all channels of something');
});
 
// User defined callbacks (this one is built in now)
$irc->in('/^:(.*)!~.* PRIVMSG (.*) :!(.*)/', function ($irc, $nick, $channel, $args) {
  $irc->msg($args, $nick, $channel); // Calls 'msg' callback, defined below
});
 
$irc->msg('/^echo (.*)/', function ($irc, $nick, $channel, $line) {
  $irc->send($irc->last, $line);
});

// Anything else here is just a shortcut for the send command
$irc->notice('#Channel :blah');
$irc->privmsg('#Channel :blah');

// Fork/Join
$child = $irc->async(function ($irc) {
  // run some asynchronous stuff
  return array('some', 'simple', 'data');
});

$output = $irc->wait($child); // $output contains above array

// Non-blocking version, read only if data is there
// $output will be false if no data was returned yet
$output = $irc->read($child);
```

Documentation
---

Documentation is available on [GitHub pages](http://zanea.github.com/ProtoIRC/).
