<?php

// Namespace B is converted first: Holder's constant initializer forces the
// lazy evaluation of A\E's case expression while B is the active conversion
// namespace. `Helper::V` inside the case expression must resolve to A\Helper
// (20), never to the decoy B\Helper (999).
namespace B {
    class Helper
    {
        public const V = 999;
    }

    class Holder
    {
        public const REF = \A\E::X;
    }
}

namespace A {
    class Helper
    {
        public const V = 20;
    }

    enum E: int
    {
        case X = Helper::V + 1;
    }
}

namespace {
    function main()
    {
        var_dump(\B\Holder::REF);
        var_dump(\A\E::X->value);
    }
}
