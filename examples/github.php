<?php
// 1:08pm - 1:42pm

$githubFeed = (object)array(
  'name' => 'ZaneA',
  'repo' => 'ProtoIRC',
  'branch' => 'master'
);

require '../protoirc.php';

$irc = new ProtoIRC('Githubbot@127.0.0.1:6667/Bottest', function ($irc) {
  $irc->send('#Bottest', 'Githubbot reportin\' in!');
});

$irc->msg('/^!name (.*)/', function ($irc, $nick, $channel, $name) use (&$githubFeed) {
  $irc->send($channel, "Setting name to \"{$name}\"..");
  $githubFeed->name = $name;
});

$irc->msg('/^!repo (.*)/', function ($irc, $nick, $channel, $repo) use (&$githubFeed) {
  $irc->send($channel, "Setting repository to \"{$repo}\"..");
  $githubFeed->repo = $repo;
});

$irc->msg('/^!branch (.*)/', function ($irc, $nick, $channel, $branch) use (&$githubFeed) {
  $irc->send($channel, "Setting branch to \"{$branch}\"..");
  $githubFeed->branch = $branch;
});

$irc->msg('/^!latest/', function ($irc, $nick, $channel) use ($githubFeed) {
  $irc->send($channel, 'Please wait..');

  $irc->async(function ($irc) use ($githubFeed, $channel) {
    $feed = @file_get_contents("https://github.com/api/v2/json/commits/list/{$githubFeed->name}/{$githubFeed->repo}/{$githubFeed->branch}");

    if (!empty($feed)) {
      $json = json_decode($feed);

      for ($i = 0; $i < 3; $i++) {
        if (isset($json->commits[$i])) {
          $commit = $json->commits[$i];

          $irc->send($channel, "{$commit->id}: {$commit->message}");
        }
      }
    } else {
      $irc->send($channel, 'Unable to fetch feed at this time');
    }
  });
});

$irc->msg('/^!commit (.*)/', function ($irc, $nick, $channel, $commit) use ($githubFeed) {
  $irc->send($channel, 'Please wait..');

  $irc->async(function ($irc) use ($githubFeed, $channel, $commit) {
    $feed = @file_get_contents("https://github.com/api/v2/json/commits/show/{$githubFeed->name}/{$githubFeed->repo}/{$commit}");

    if (!empty($feed)) {
      $json = json_decode($feed);

      $commit = $json->commit;

      $irc->send($channel, "{$commit->id}: {$commit->message}");
    } else {
      $irc->send($channel, 'Unable to fetch commit at this time');
    }
  });
});

$irc->go();
