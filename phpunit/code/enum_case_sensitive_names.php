<?php

// Enum class names are case-insensitive, but case names are case-sensitive:
// `A` and `a` are distinct cases. Holder registers first, so resolving REF
// fetches F::A while A's own value walks into F::a — both cases sit in the
// in-progress guard at once, and a cycle key that lowercased the case name
// would collapse "F::A" and "F::a" into one entry and report a
// self-reference that does not exist.
class Holder
{
    public const REF = F::A;
}

enum F: int
{
    case A = F::a->value - 1;
    case a = 2 + 1;
}

function main()
{
    var_dump(Holder::REF);
    var_dump(F::A->value);
    var_dump(F::a->value);
}
