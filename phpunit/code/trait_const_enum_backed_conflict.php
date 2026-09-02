<?php
// A shared backing scalar does not make cases of two different backed enums
// the same value: Zend compares the case objects, not their backing values.
enum E1: string
{
    case Value = 'x';
}

enum E2: string
{
    case Value = 'x';
}

trait T1
{
    const X = E1::Value;
}

trait T2
{
    const X = E2::Value;
}

class C
{
    use T1;
    use T2;
}

function main() {}
