<?php
trait A
{
    public function f(): void {}
}

class C
{
    use A {
        A::missing as g;
    }
}

function main() {}
