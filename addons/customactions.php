#!/usr/bin/php
<?php
$irc->msg('/^echo (.*)/', function ($irc, $args) {
        $irc($irc->last, $args);
});

$irc->msg('/^ip/', function ($irc) {
        $irc->async(function ($irc) {
                $irc($irc->last, file_get_contents('http://whatismyip.org'), 'yellow');
        });
});

$irc->in('/^:(.*)!~.* PRIVMSG (.*) :!(.*)/', function ($irc, $nick, $channel, $args) {
        $irc->callMsg($args);
});
