<?php
// Enum-case identity must survive INDIRECT constant references: each trait
// reads a class constant that holds a case of a different enum, so the two
// definitions of VALUE are different case objects and Zend rejects the
// composition even though both cases share the name `Value`.
enum E1
{
    case Value;
}

enum E2
{
    case Value;
}

class H1
{
    public const ITEM = E1::Value;
}

class H2
{
    public const ITEM = E2::Value;
}

trait T1
{
    public const VALUE = H1::ITEM;
}

trait T2
{
    public const VALUE = H2::ITEM;
}

class C
{
    use T1;
    use T2;
}

function main() {}
