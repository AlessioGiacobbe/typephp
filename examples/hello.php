<?php

function main(int $argc, array $argv): int
{
    $a = 1;
    $b = 2;
    $c = 3.12343;
    $d = 'hello';
    $e = ['hello' => 1, 'world' => 33.43];

    for ($i = 1; $i < $argc; ++$i) {
        $a += $argv[$i];
    }

    $argc = 999;
    $a = $b * 13;
    $a += $b << 4;
    $a += $b >> 1;

    $_tmp = $e['hello'];
    $_tmp -= 4;
    $_tmp *= 5;
    $_tmp /= 10;
    $_tmp %= 10;

    var_dump($_tmp, 1000);

    echo "value:=" . ($a + $b);

    echo "value:=", $a, $b;

    return $a * $b;
}
