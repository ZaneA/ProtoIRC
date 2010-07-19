<?php
//
// Prototyping helpers for sample ProtoIRC client
// Author: Zane Ashby
//

$saved_handlers = array();
$filename = '';

$irc->command('/^\/(proto|new)/', function ($irc, $cmd) {
        global $filename, $saved_handlers;

        $filename = tempnam('/tmp', 'proto');

        // Below is some example code that will be written to the file
        // before it is opened in your editor
        $prototype = <<<'PROTO'
#!/usr/bin/php
<?php
$irc->in('/^:(.*)!.* PRIVMSG (.*) :!echo (.*)/', function ($irc, $nick, $channel, $args) {
        $irc->send($channel, "Echoing {$args} for you, {$nick}", 'yellow');
});
PROTO;

        file_put_contents($filename, $prototype);

        pclose(popen('/bin/sh -c "$EDITOR '.$filename.' &"', 'r'));

        $filem = filemtime($filename);

        $irc->timer(5, function ($irc) use (&$filem) {
                global $filename, $saved_handlers;

                clearstatcache(true, $filename);

                if (filemtime($filename) != $filem) {
                        $filem = filemtime($filename);

                        $irc->handlers = $saved_handlers;

                        // PHP lint to check the include file for correctness before loading it
                        // Hopefully this will prevent the bot from bombing on errors
                        exec("php -l {$filename}", $output = array(), $returncode);

                        if ($returncode == 0) {
                                ob_start();

                                include($filename);

                                ob_end_clean();
                                $irc->termEcho("Reloaded {$filename}\n", 'lt.green');
                        } else {
                                $irc->termEcho("Errors were found in {$filename}\n", 'lt.red');
                        }
                }
        });

        $saved_handlers = $irc->handlers;
});

$irc->command('/^\/save (.*)/', function ($irc, $newfilename) {
        global $filename;

        if (!empty($filename)) {
                $irc->timer(5, null); // unset timer

                rename($filename, 'addons/'.$newfilename.'.php');

                $irc->termEcho("Moved {$filename} to addons/{$newfilename}.php\n", 'lt.green');

                $filename = '';
        }
});

$irc->command('/^\/php (.*)/', function ($irc, $code) {
        if (@eval("return true; {$code}")) {
                eval($code);
        } else {
                $irc->termEcho("Error in eval'd code\n", 'lt.red');
        }
});
