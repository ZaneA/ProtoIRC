#!/usr/bin/php
<?php
$irc->msg('/^!echo (.*)/', function ($irc, $nick, $channel, $args) {
        $irc->send($irc->last, $args);
});

$irc->msg('/^!ip/', function ($irc, $nick, $channel) {
        $irc->async(function ($irc) {
                $irc->send($irc->last, @file_get_contents('http://whatismyip.org'), 'yellow');
        });
});

$irc->msg('/^!bash/', function ($irc, $nick, $channel) {
        $irc->async(function ($irc) {
                $bash = @file_get_contents('http://bash.org/?random1');
                if (preg_match('/<p class="qt">(.*?)<\/p>/s', $bash, $results)) {
                        $irc->send($irc->last, html_entity_decode(str_replace('<br />', '', $results[1])));
                }
        });
});

$irc->in('/^:(.*)!~.* PRIVMSG (.*) :(.*)(?#msg)/', function ($irc, $nick, $channel, $args) {
        $irc->msg($args, $nick, $channel);
        return true;
});
