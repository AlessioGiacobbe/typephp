<?php
// The SAME enum case reached through an INHERITED class constant: H1 does
// not declare ITEM itself, so the lookup walks up to BaseH. One trait reads
// the constant through the child, the other through the parent, and one
// reads the case directly — all three spellings are the same case object,
// so composing is legal.
enum E1
{
    case Value;
    case Other;
}

class BaseH
{
    public const ITEM = E1::Value;
}

class H1 extends BaseH
{
}

trait T1
{
    public const VALUE = H1::ITEM;
}

trait T2
{
    public const VALUE = BaseH::ITEM;
}

trait T3
{
    public const VALUE = E1::Value;
}

class C
{
    use T1;
    use T2;
    use T3;
}

function main() {}
