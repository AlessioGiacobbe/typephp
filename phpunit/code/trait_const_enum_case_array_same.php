<?php
// Enum-case identity nests recursively: two arrays holding the SAME enum case
// (and equal scalars) are one definition, so composing is legal.
enum E
{
    case A;
    case B;
}

trait T1
{
    const X = [E::A, 1];
}

trait T2
{
    const X = [E::A, 1];
}

class C
{
    use T1;
    use T2;
}

function main() {}
