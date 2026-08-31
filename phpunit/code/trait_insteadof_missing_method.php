<?php
trait A
{
    public function f(): void {}
}

trait B
{
    public function g(): void {}
}

class C
{
    use A, B {
        B::f insteadof A;
    }
}

function main() {}
