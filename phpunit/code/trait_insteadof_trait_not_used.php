<?php
trait A
{
    public function f(): void {}
}

trait B
{
    public function f(): void {}
}

trait D
{
    public function f(): void {}
}

class C
{
    use A, B {
        A::f insteadof B, D;
    }
}

function main() {}
