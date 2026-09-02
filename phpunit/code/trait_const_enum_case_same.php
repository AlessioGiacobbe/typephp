<?php
// The SAME enum case in both traits is one definition: composing is legal.
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
    const X = E1::Value;
}

class C
{
    use T1;
    use T2;
}

function main() {}
