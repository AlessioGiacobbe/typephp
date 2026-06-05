<?php
use native_types;

class Foo {
    static public int $a;
}

function isset_static(int $n) {
    for ($i = 0; $i < $n; ++$i) {
        $x = isset(Foo::$a);
    }
}

function static_prop_add(int $n) {
    Foo::$a = 0;
    for ($i = 0; $i < $n; ++$i) {
        Foo::$a += 13;
    }
    var_dump(Foo::$a);
}

function main(): void {
    isset_static(100000);
    static_prop_add(100000);
}