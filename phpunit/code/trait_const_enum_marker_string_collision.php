<?php
// A user string can spell ANY byte sequence (PHP strings are binary-safe), so
// no marker string can stand in for an enum case: Zend rejects this
// composition because an enum-case object and a string are different values.
enum E
{
    case A;
}

trait T1
{
    const X = E::A;
}

trait T2
{
    const X = "\0enum-case\0e::A";
}

class C
{
    use T1;
    use T2;
}

function main() {}
