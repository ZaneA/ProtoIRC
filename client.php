<?php
//
// Sample ProtoIRC Client/Bot
// Author: Zane Ashby
//

require 'protoirc.php';


// Create IRC Class
$irc = new ProtoIRC('ProtoBot@10.1.1.9:6667/Bottest', function ($irc) {
  $irc->notice('#Bottest :Hey there!')->send('#Bottest', "Hey there!");
});


// Include addons
foreach (glob('addons/*.php') as $addon) {
  include $addon;
}


// Send raw IRC data by typing "/quote SOME DATA TO SEND"
$irc->stdin('/^\/(quote|raw) (.*)/', function ($irc, $command, $data) {
  $irc->send($data);
});


// Execute command by typing "/exec command" and send output to current channel
$irc->stdin('/^\/exec (.*)/', function ($irc, $args) {
  exec($args, $output);

  $irc->send($irc->last, $output);
});


// Send to channel by typing "#channel, message"
$irc->stdin('/^([#\-[:alnum:]]*), (.*)/', function ($irc, $channel, $msg) {
  $irc->send("{$channel}", $msg);
});


// Catch-all: Send to default channel
$irc->stdin('/(.*)/', function ($irc, $msg) {
  $irc->send($irc->last, $msg);
});


// Catch outgoing messages and print them
$irc->out('/^PRIVMSG (.*) :(.*)/', function ($irc, $channel, $msg) {
  $irc->stdout("{$irc->ansi->_black}({$channel}.{$irc->ansi->_blue}{$irc->nick}{$irc->ansi->_black})> {$irc->ansi->_white}{$msg}\n"); 
});


// Display the topic when joining a channel
$irc->in('/^:.* 332 .* (.*) :(.*)/', function ($irc, $channel, $topic) {
  $irc->stdout("The topic of {$channel} is {$topic}\n", '_brown');
});


// Someone is joining or parting
$irc->in('/^:(.*)!.* (JOIN|PART) :?(.*)/', function ($irc, $nick, $cmd, $channel) {
  if ($cmd == 'JOIN') {
    $irc->stdout(">> {$nick} has joined {$channel}\n", '_green');
  } else {
    $irc->stdout("<< {$nick} has left {$channel}\n", '_red');
  }
});


// Someone has messaged a channel or PM'd us, so print it
$irc->in('/^:(.*)!.* PRIVMSG (.*) :(.*)/', function ($irc, $nick, $channel, $msg) {
  $irc->stdout("{$irc->ansi->_black}({$channel}.{$irc->ansi->blue}{$nick}{$irc->ansi->_black})> {$irc->ansi->_white}{$msg}\n");
});


// Catch-all: Print raw line to terminal for debugging/hacking
$irc->in('/(.*)/', function ($irc, $line) {
  $irc->stdout("<< {$line}\n", '_black');
});


// Everything is bound, so lets go!
$irc->go();
