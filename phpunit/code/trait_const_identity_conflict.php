<?php
trait A
{
    const X = 1;
}

trait B
{
    const X = 1.0;
}

class C
{
    use A;
    use B;
}

function main() {}
