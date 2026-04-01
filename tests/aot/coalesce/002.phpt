--TEST--
Test ?? operator
--FILE--
<?php

function f($x)
{
    printf("%s(%d)\n", __FUNCTION__, $x);
    return $x;
}

function main() {
    $r1 = f(1);
    $r2 = f(2);
    $a = f(null) ?? $r1 ?? $r2;
    var_dump($a);
}
?>
--EXPECTF--
f(1)
f(2)
f(0)
int(1)
