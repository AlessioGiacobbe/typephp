<?php
trait A
{
    const int X = 1 + 1;
    public array $p = [1, 2];
    public float $f = 1;
}

trait B
{
    const int X = 2;
    public array $p = array(1, 2);
    public float $f = 1.0;
}

class C
{
    use A;
    use B;
}

// The class's own members are also compared by value with trait members.
trait T
{
    const int Y = 2 + 3;
}

class D
{
    use T;

    const int Y = 5;
}

function main() {}
