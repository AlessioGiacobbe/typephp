<?php
// The SAME enum case reached through an intermediate class constant is one
// definition: both traits' VALUE evaluate to E1::Value, so composing is
// legal — the indirection through H1::ITEM must not lose the case identity
// (a fresh non-interned value per evaluation would falsely conflict here).
enum E1
{
    case Value;
    case Other;
}

class H1
{
    public const ITEM = E1::Value;
}

trait T1
{
    public const VALUE = H1::ITEM;
}

trait T2
{
    public const VALUE = H1::ITEM;
}

class C
{
    use T1;
    use T2;
}

function main() {}
