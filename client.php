<?php
//
// Sample ProtoIRC Client/Bot
// Author: Zane Ashby
//

require 'protoirc.php';


// Create IRC Class
$irc = new ProtoIRC('ProtoBot@10.1.1.9:6667/Bottest', function ($irc) {
        $irc->notice('#Bottest :Hey there!')->send('#Bottest', "{$irc->yellow}Hey there!{$irc->default}test");
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
        $irc->stdout("{$irc->ansi->lt.black}({$channel}.{$irc->ansi->lt.blue}{$irc->nick}{$irc->ansi->lt.black})> {$irc->ansi->lt.white}{$msg}\n"); 
});


// Display the topic when joining a channel
$irc->in('/^:.* 332 .* (.*) :(.*)/', function ($irc, $channel, $topic) {
        $irc->stdout("The topic of {$channel} is {$topic}\n", 'lt.brown');
});


// Someone is joining or parting
$irc->in('/^:(.*)!.* (JOIN|PART) :?(.*)/', function ($irc, $nick, $cmd, $channel) {
        if ($cmd == 'JOIN') {
                $irc->stdout(">> {$nick} has joined {$channel}\n", 'lt.green');
        } else {
                $irc->stdout("<< {$nick} has left {$channel}\n", 'lt.red');
        }
});


// Someone has messaged a channel or PM'd us, so print it
$irc->in('/^:(.*)!.* PRIVMSG (.*) :(.*)/', function ($irc, $nick, $channel, $msg) {
        $irc->stdout("{$irc->ansi->lt.black}({$channel}.{$irc->ansi->blue}{$nick}{$irc->ansi->lt.black})> {$irc->ansi->lt.white}{$msg}\n");
});


// Catch-all: Print raw line to terminal for debugging/hacking
$irc->in('/(.*)/', function ($irc, $line) {
        $irc->stdout("<< {$line}\n", 'lt.black');
});


// Everything is bound, so lets go!
$irc->go();
