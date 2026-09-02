<?php
// Enum-case identity nests recursively: arrays holding same-named cases of
// two DIFFERENT enums hold distinct case objects, so the definitions conflict.
enum E1
{
    case Value;
}

enum E2
{
    case Value;
}

trait T1
{
    const X = [E1::Value];
}

trait T2
{
    const X = [E2::Value];
}

class C
{
    use T1;
    use T2;
}

function main() {}
