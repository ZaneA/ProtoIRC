#!/usr/bin/php
<?php
$irc->msg('/^echo (.*)/', function ($irc, $args) {
        $irc($irc->lastChannel, $args);
});

$irc->msg('/^ip/', function ($irc) {
        $irc->async(function ($irc) {
                $irc($irc->lastChannel, file_get_contents('http://whatismyip.org'), 'yellow');
        });
});

$irc->in('/^:(.*)!~.* PRIVMSG (.*) :!(.*)/', function ($irc, $nick, $channel, $args) {
        $irc->call_msg($args);
});
