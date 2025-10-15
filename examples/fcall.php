<?php
function foo(int $a, int $b): int {
    return $a + $b;
}

class A {
    function foo(int $a, int $b): int
    {
        return $a + $b;
    }
}


//foo(1, 2);
//max(1, 2, 3);

$o = new A;
$o->foo(1, 2);