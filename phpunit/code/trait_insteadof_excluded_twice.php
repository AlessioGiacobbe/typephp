<?php
// Both rules pass the winner-existence check, but the same trait method
// (B::f) may only be excluded once.
trait A
{
    public function f(): void {}
}

trait B
{
    public function f(): void {}
}

class X
{
    use A, B {
        A::f insteadof B;
        A::f insteadof B;
    }
}

function main() {}
