<?php
trait A
{
    public array $p = [1, 2];
}

trait B
{
    public array $p = [2, 1];
}

class C
{
    use A;
    use B;
}

function main() {}
