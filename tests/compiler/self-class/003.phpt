--TEST--
Class constant referenced via self:: / parent:: / ClassName:: as a property default value
--FILE--
<?php

declare(strict_types=1);

class A
{
    const A = 1;
    const B = 2;
}

class B extends A
{
    // self:: referencing an inherited constant.
    // Bug: self::A was incorrectly resolved as B::B::A and the lookup failed.
    public $a = self::A;
    // self:: referencing a constant declared in the same class.
    const LOCAL = 10;
    public $local = self::LOCAL;
    // parent:: referencing a parent constant.
    public $b = parent::A;
    // explicit class name.
    public $c = A::B;
}

function main()
{
    $test = new B;
    var_dump($test->a);
    var_dump($test->local);
    var_dump($test->b);
    var_dump($test->c);
}
?>
--EXPECT--
int(1)
int(10)
int(1)
int(2)
