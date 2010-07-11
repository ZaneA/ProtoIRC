<?php
//
// Prototyping helpers for sample ProtoIRC client
// Author: Zane Ashby
//

$saved_handlers = array();

$irc->command('/^\/(proto|new)/', function ($irc, $cmd) {
        global $saved_handlers;
        $saved_handlers = $irc->handlers;

        $filename = tempnam('.', 'proto');

        // Below is some example code that will be written to the file
        // before it is opened in your editor
        $prototype = <<<'PROTO'
#!/usr/bin/php
<?php
$irc->command('/^\/echo (.*)/', function ($irc, $args) {
        $irc->send($irc->lastChannel, $irc->ircColor('lt.blue').$args.$irc->ircColor());
        $irc->termEcho("Echoing {$args}\n", 'lt.red');
});

$irc->in('/^:(.*)!~.* PRIVMSG (.*) :!echo (.*)/', function ($irc, $nick, $channel, $args) {
        $irc->send($channel, $args);
});
PROTO;

        file_put_contents($filename, $prototype);

        pclose(popen('/bin/sh -c "$EDITOR '.$filename.' &"', 'r'));
});

$irc->command('/^\/save/', function ($irc) {
        global $saved_handlers;
        $saved_handlers = $irc->handlers;
});

$irc->command('/^\/(load|include) (.*)/', function ($irc, $cmd, $filename) {
        global $saved_handlers;
        $irc->handlers = $saved_handlers;

        $filename = trim($filename);

        // PHP lint to check the include file for correctness before loading it
        // Hopefully this will prevent the bot from bombing on errors
        exec("php -l {$filename}", $output = array(), $returncode);

        if ($returncode == 0) {
                ob_start();

                include($filename);

                ob_end_clean();
        } else {
                $irc->termEcho("Errors were found in {$filename}\n", 'lt.red');
        }
});

$irc->command('/^\/php (.*)/', function ($irc, $code) {
        if (@eval("return true; {$code}")) {
                eval($code);
        } else {
                $irc->termEcho("Error in eval'd code\n", 'lt.red');
        }
});
