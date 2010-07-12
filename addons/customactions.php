#!/usr/bin/php
<?php
$irc->msg('/^echo (.*)/', function ($irc, $args) {
        $irc->send($irc->last, $args);
});

$irc->msg('/^ip/', function ($irc) {
        $irc->async(function ($irc) {
                $irc->send($irc->last, @file_get_contents('http://whatismyip.org'), 'yellow');
        });
});

$irc->msg('/^bash/', function ($irc) {
        $irc->async(function ($irc) {
                $bash = @file_get_contents('http://bash.org/?random1');
                if (preg_match('/<p class="qt">(.*?)<\/p>/s', $bash, $results)) {
                        $irc->send($irc->last, html_entity_decode(str_replace('<br />', '', $results[1])));
                }
        });
});

$irc->in('/^:(.*)!~.* PRIVMSG (.*) :!(.*)/', function ($irc, $nick, $channel, $args) {
        $irc->msg($args);
});
