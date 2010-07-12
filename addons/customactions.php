#!/usr/bin/php
<?php
$irc->msg('/^echo (.*)/', function ($irc, $args) {
        $irc->send($irc->last, $args);
});

$irc->msg('/^ip/', function ($irc) {
        $irc->async(function ($irc) {
                $irc->send($irc->last, file_get_contents('http://whatismyip.org'), 'yellow');
        });
});

$irc->in('/^:(.*)!~.* PRIVMSG (.*) :!(.*)/', function ($irc, $nick, $channel, $args) {
        $irc->msg($args);
});
