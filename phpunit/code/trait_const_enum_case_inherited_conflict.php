<?php
// Different enum cases reached through INHERITED class constants: neither
// H1 nor H2 declares ITEM, so both lookups walk up to a parent whose ITEM
// holds a case of a different enum. The two definitions of VALUE are
// different case objects and the composition is rejected.
enum E1
{
    case Value;
}

enum E2
{
    case Value;
}

class B1
{
    public const ITEM = E1::Value;
}

class B2
{
    public const ITEM = E2::Value;
}

class H1 extends B1
{
}

class H2 extends B2
{
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
