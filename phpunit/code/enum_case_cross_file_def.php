<?php

// Declaring half of the cross-file pair (see enum_case_cross_file_ref.php):
// the case expression names Provider through this file's `use` import, so a
// lazy evaluation triggered from another file must restore this context.
namespace Lib {
    class Provider
    {
        public const BASE = 40;
    }
}

namespace A {
    use Lib\Provider;

    enum E: int
    {
        case X = Provider::BASE + 2;
    }
}
