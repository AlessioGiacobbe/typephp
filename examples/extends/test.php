<?php

class A
{
    public function __construct()
    {
        echo "A::__construct()\n";
    }
}

class B extends A
{
    public function __construct()
    {
        parent::__construct();
        echo "B::__construct()\n";
    }

    public function foo()
    {

        var_dump(__METHOD__);
    }
}

class C extends ArrayObject {

}

function main()
{
    $o = new B;
    $o->foo();

    $c = new C;
    $c->offsetSet(0, 1);
    $c->offsetSet(1, 2);
    var_dump($c);
    var_dump($c->foo());
}
