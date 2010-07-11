<?php
//
// Sample ProtoIRC Client/Bot
// Author: Zane Ashby
//

require('protoirc.php');


// Create IRC Class
$irc = new ProtoIRC('10.1.1.9', 6667, 'ProtoBot', function ($irc) {
        // Connected, so join our channel
 
        $irc->send('JOIN #Bottest');
});


// Include addons
foreach (glob('addons/*.php') as $addon) {
        include $addon;
}


// Send raw IRC data by typing "/quote SOME DATA TO SEND"
$irc->command('/^\/(quote|raw) (.*)/', function ($irc, $command, $data) {
        $irc->send($data);
});


// Execute command by typing "/exec command" and send output to current channel
$irc->command('/^\/exec (.*)/', function ($irc, $args) {
        $output = Array();

        exec($args, $output);

        $irc->send($irc->lastChannel, $output);
});


// Send to channel by typing "#channel, message"
$irc->command('/^(#.*), (.*)/', function ($irc, $channel, $msg) {
        $irc->send("{$channel}", $msg);
});


// Catch-all: Send to default channel
$irc->command('/(.*)/', function ($irc, $msg) {
        if (empty($msg)) return;

        $irc->send($irc->lastChannel, $msg);
});


// Catch outgoing messages and print them
$irc->out('/^PRIVMSG (.*) :(.*)/', function ($irc, $channel, $msg) {
        $irc->termEcho("({$channel}.", 'lt.black').$irc->termEcho($irc->nick, 'lt.blue').$irc->termEcho(')> ', 'lt.black').$irc->termEcho("{$msg}\n", 'lt.white'); 
});


// Display the topic when joining a channel
$irc->in('/^:.* 332 .* (.*) :(.*)/', function ($irc, $channel, $topic) {
        $irc->termEcho("The topic of {$channel} is {$topic}\n", 'lt.brown');
});


// Someone is joining or parting
$irc->in('/^:(.*)!~.* (JOIN|PART) :?(.*)/', function ($irc, $nick, $cmd, $channel) {
        if ($cmd == 'JOIN') {
                $irc->termEcho(">> {$nick} has joined {$channel}\n", 'lt.green');
        } else {
                $irc->termEcho("<< {$nick} has left {$channel}\n", 'lt.red');
        }
});


// Someone has messaged a channel or PM'd us, so print it
$irc->in('/^:(.*)!~.* PRIVMSG (.*) :(.*)/', function ($irc, $nick, $channel, $msg) {
        $irc->termEcho("({$channel}.", 'lt.black').$irc->termEcho($nick, 'blue').$irc->termEcho(')> ', 'lt.black').$irc->termEcho("{$msg}\n", 'lt.white'); 
});


// Catch-all: Print raw line to terminal for debugging/hacking
$irc->in('/(.*)/', function ($irc, $line) {
        $irc->termEcho("<< {$line}\n", 'lt.black');
});


// Everything is bound, so lets go!
// In a while loop, so reconnect if we die
while (true) {
        $irc->go();
}
?>
