<?php
function main()
{
    $a = [1, 2, 3];
    $b = &$a;
    $b[] = 5;

    $c = &$b;
    $c [] = 6;
    var_dump($a, $b, $c);
}
