<?php
trait A
{
    public function f(): void {}
}

trait B
{
    public function h(): void {}
}

class C
{
    use A {
        B::h as g;
    }
}

function main() {}
