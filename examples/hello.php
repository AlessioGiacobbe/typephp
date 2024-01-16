<?php

function main(int $argc, array $argv): int
{
    $a = 1;
    $b = 2;
    $c = 3.12343;
    $d = 'hello';
    $e = ['hello' => 1, 'world' => 33.43];

    $argc = 999;
    $a = $b * 13;

    echo "value:=" . ($a + $b);

    return $a * $b;
}
