<?php
trait Inner
{
    public function f(): void {}
}

trait Outer
{
    use Inner;
}

// Aliases resolve methods arriving from NESTED traits, both unqualified and
// qualified with the directly-used trait.
class UsesNested
{
    use Outer {
        f as g;
    }
}

class UsesNestedQualified
{
    use Outer {
        Outer::f as h;
    }
}

trait Winner
{
    public function m(): void {}
}

trait Loser
{
    public function other(): void {}
}

// The overridden trait need not declare the method a precedence rule names.
class PrecedenceLoserMissing
{
    use Winner, Loser {
        Winner::m insteadof Loser;
    }
}

function main() {}
