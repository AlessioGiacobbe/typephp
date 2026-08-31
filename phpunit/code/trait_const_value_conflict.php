<?php
trait A
{
    const int X = 1;
}

trait B
{
    const int X = 3;
}

class C
{
    use A;
    use B;
}

function main() {}
