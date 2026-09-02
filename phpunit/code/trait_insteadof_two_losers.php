<?php
// One winner excluding two different losers is legal: each loser method is
// excluded exactly once.
trait A
{
    public function f(): void {}
}

trait B
{
    public function f(): void {}
}

trait C
{
    public function f(): void {}
}

class X
{
    use A, B, C {
        A::f insteadof B;
        A::f insteadof C;
    }
}

function main() {}
