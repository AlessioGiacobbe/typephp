<?php

function main(): int
{
    global $a;
    $a = 100;

    var_dump($GLOBALS['a']);
    var_dump($a);
    var_dump(gettype($_SERVER));
    var_dump($_SERVER['argc']);
    var_dump($_SERVER['argv']);
    return 0;
}
