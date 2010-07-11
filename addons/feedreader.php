#!/usr/bin/php
<?php
$irc->in('/^:(.*)!~.* PRIVMSG (.*) :!feed (.*)/', function ($irc, $nick, $channel, $feed) {
        $irc->async(function ($irc) use ($channel, $feed) {
                if (!file_exists('lastRSS.php')) {
                        $irc->send($channel, 'Please place lastRSS.php in the ProtoIRC directory to use', 'red');
                        return;
                }

                $irc->send($channel, "Fetching {$feed}, won't be a moment");

                include 'lastRSS.php';

                $rss = new lastRSS();

                $rss->cache_dir = 'temp';
                $rss->cache_time = 1200;
                $rss->items_limit = 3;

                $rs = $rss->get($feed);

                if (!$rs) {
                        $irc->send($channel, "Error fetching {$feed}, sorry", 'red');
                } else {
                        foreach ($rs['items'] as $item) {
                                $irc->send($channel, $irc->ircColor('yellow').$item['title'].$irc->ircColor()." ({$item['link']})");
                        }
                }
        });
});
