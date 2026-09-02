<?php
class ParentClass {}

class ChildClass extends ParentClass
{
    public self $again;

    public const self|int MARKER = 1;

    public function m(self $a, ?parent $b): self|static
    {
        return $this;
    }
}

trait LateBound
{
    public function pass(parent $x): parent
    {
        return $x;
    }
}

interface SelfContract
{
    public function m(self $x): self;
}

enum SelfEnum: int
{
    case One = 1;

    public function pick(self $other): self
    {
        return $other;
    }
}

function closureKeepsRuntimeScope(): int
{
    $f = function (self $x): self {
        return $x;
    };
    return 1;
}

function main() {}
