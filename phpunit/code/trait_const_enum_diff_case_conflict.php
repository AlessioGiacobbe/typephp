<?php
// Different cases of the SAME enum are different case objects: conflict.
enum E1
{
    case Value;
    case Other;
}

trait T1
{
    const X = E1::Value;
}

trait T2
{
    const X = E1::Other;
}

class C
{
    use T1;
    use T2;
}

function main() {}
