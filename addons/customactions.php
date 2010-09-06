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

$irc->msg('/^!feed (.*)/', function ($irc, $nick, $channel, $feed) {
        $irc->async(function ($irc) use ($feed) {
                if (!file_exists('lastRSS.php')) {
                        $irc->send($irc->last, 'Please place lastRSS.php in the ProtoIRC directory to use', 'red');
                        return;
                }

                $irc->send($irc->last, "Fetching {$feed}, won't be a moment");

                include 'lastRSS.php';

                $rss = new lastRSS();

                $rss->cache_dir = 'temp';
                $rss->cache_time = 1200;
                $rss->items_limit = 3;

                $rs = $rss->get($feed);

                if (!$rs) {
                        $irc->send($irc->last, "Error fetching {$feed}, sorry", 'red');
                } else {
                        foreach ($rs['items'] as $item) {
                                $irc->send($irc->last, "{$irc->yellow}{$item['title']}{$irc->default} ({$item['link']})");
                        }
                }
        });
});

/*$irc->in('/^:(.*)!.* PRIVMSG (.*) :(.*)(?#msg)/', function ($irc, $nick, $channel, $args) {
        $irc->msg($args, $nick, $channel);
});*/
