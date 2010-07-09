<?php
//
// Sample ProtoIRC Client/Bot
// Author: Zane Ashby
//

require('protoirc.php');


// Create IRC Class
$irc = new ProtoIRC('10.1.1.9', 6667, 'ProtoBot', function ($irc, $args, $line) {
        // Connected, so join our channel
 
        $irc->send('JOIN #Bottest');
});


// Include addons
foreach (glob('addons/*.php') as $addon) {
        include $addon;
}


// Send raw IRC data by typing "/quote SOME DATA TO SEND"
$irc->bind(COMMAND, '/^\/(quote|raw) (.*)/', function ($irc, $args, $line) {
        $irc->send($args[1]);
});


// Execute command by typing "/exec command" and send output to current channel
$irc->bind(COMMAND, '/^\/exec (.*)/', function ($irc, $args, $line) {
        $output = Array();

        exec($args[0], $output);

        foreach ($output as $line) {
                $irc->send($irc->lastChannel, $line);
        }
});


// Send to channel by typing "#channel, message"
$irc->bind(COMMAND, '/^#(.*), (.*)/', function ($irc, $args, $line) {
        $irc->send("#{$args[0]}", $args[1]);
});


// Catch-all: Send to default channel
$irc->bind(COMMAND, '/(.*)/', function ($irc, $args, $line) {
        if (empty($args[0])) return;

        $irc->send($irc->lastChannel, $args[0]);
});


// Catch outgoing messages and print them
$irc->bind(IRC_OUT, '/^PRIVMSG (.*) :(.*)/', function ($irc, $args, $line) {
        $irc->termEcho("({$args[0]}.", 'lt.black').$irc->termEcho($irc->nick, 'lt.blue').$irc->termEcho(')> ', 'lt.black').$irc->termEcho("{$args[1]}\n", 'lt.white'); 
});


// Display the topic when joining a channel
$irc->bind(IRC_IN, '/^:(.*) 332 (.*) (.*) :(.*)/', function ($irc, $args, $line) {
        $irc->termEcho("The topic of {$args[2]} is {$args[3]}\n", 'lt.brown');
});


// Someone is joining or parting
$irc->bind(IRC_IN, '/^:(.*)!~(.*) (JOIN|PART) :?(.*)/', function ($irc, $args, $line) {
        if ($args[2] == 'JOIN') {
                $irc->termEcho(">> {$args[0]} has joined {$args[3]}\n", 'lt.green');
        } else {
                $irc->termEcho("<< {$args[0]} has left {$args[3]}\n", 'lt.red');
        }
});


// Someone has messaged a channel or PM'd us, so print it
$irc->bind(IRC_IN, '/^:(.*)!~(.*) PRIVMSG (.*) :(.*)/', function ($irc, $args, $line) {
        $irc->termEcho("({$args[2]}.", 'lt.black').$irc->termEcho($args[0], 'blue').$irc->termEcho(')> ', 'lt.black').$irc->termEcho("{$args[3]}\n", 'lt.white'); 
});


// Catch-all: Print raw line to terminal for debugging/hacking
$irc->bind(IRC_IN, '/(.*)/', function ($irc, $args, $line) {
        $irc->termEcho("<< {$args[0]}\n", 'lt.black');
});


// Everything is bound, so lets go!
// In a while loop, so reconnect if we die
while (true) {
        $irc->go();
}
?>
