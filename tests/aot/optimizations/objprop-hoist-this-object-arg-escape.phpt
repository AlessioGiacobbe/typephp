--TEST--
SSA object prop: this object argument escape prevents property hoisting
--FILE--
<?php
use native_types;

class Foo {
    public int $a;

    public function run(): void {
        $this->a = 1;

        $fn = 'make_ref';
        $fn($this);
        $this->a += 1;

        var_dump($this->a);
    }
}

function make_ref(Foo $o): void {
    $ref =& $o->a;
    $ref = 99;
}

function main(): void {
    (new Foo())->run();
}
?>
--EXPECT--
int(100)
