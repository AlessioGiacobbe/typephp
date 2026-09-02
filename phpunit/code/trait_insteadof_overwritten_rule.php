<?php
// Two precedence rules name the same loser (B::f): the later C::f rule must
// not absolve the earlier A::f rule, whose winner method does not exist.
trait A
{
    public function other(): void {}
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
        C::f insteadof B;
    }
}

function main() {}
