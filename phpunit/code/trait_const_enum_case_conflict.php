<?php
// Same-named cases of two DIFFERENT enums are distinct case objects in Zend,
// so the two definitions of X conflict even though both cases share the name.
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
