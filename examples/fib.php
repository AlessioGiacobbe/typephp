<?php
function fib(int $n): int
{
    if ($n == 1 || $n == 2) {
        return 1;
    } else {
        return fib($n - 1) + fib($n - 2);
    }
}

function main()
{
    global $argv;
    $n = $argv[2];
    $begin = microtime(true);
    echo fib($n) . "\n";
    echo "Time: " . (microtime(true) - $begin) . "\n";
}
