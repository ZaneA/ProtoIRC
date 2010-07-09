<?php
//
// Prototyping helpers for sample ProtoIRC client
// Author: Zane Ashby
//

$saved_handlers = array();

$irc->bind(COMMAND, '/^\/(proto|new)/', function ($irc, $args, $line) {
        global $saved_handlers;
        $saved_handlers = $irc->handlers;

        $filename = tempnam('.', 'proto');

        // Below is some example code that will be written to the file
        // before it is opened in your editor
        $prototype = <<<'PROTO'
#!/usr/bin/php
<?php
$irc->bind(COMMAND, '/^\/echo (.*)/', function ($irc, $args, $line) {
        $irc->send($irc->lastChannel, $irc->ircColor('lt.blue').$args[0].$irc->ircColor());
        $irc->termEcho("Echoing {$args[0]}\n", 'lt.red');
});

$irc->bind(IRC_IN, '/^:(.*)!~(.*) PRIVMSG (.*) :(.*)/', function ($irc, $args, $line) {
        $irc->send($args[2], $args[3]);
});
PROTO;

        file_put_contents($filename, $prototype);

        pclose(popen('/bin/sh -c "$EDITOR '.$filename.' &"', 'r'));
});

$irc->bind(COMMAND, '/^\/save/', function ($irc, $args, $line) {
        global $saved_handlers;
        $saved_handlers = $irc->handlers;
});

$irc->bind(COMMAND, '/^\/(load|include) (.*)/', function ($irc, $args, $line) {
        global $saved_handlers;
        $irc->handlers = $saved_handlers;

        $filename = trim($args[1]);

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

$irc->bind(COMMAND, '/^\/php (.*)/', function ($irc, $args, $line) {
        if (@eval("return true; {$args[0]}")) {
                eval($args[0]);
        } else {
                $irc->termEcho("Error in eval'd code\n", 'lt.red');
        }
});
