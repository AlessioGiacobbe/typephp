<?php

// Referencing half of the cross-file pair (see enum_case_cross_file_def.php).
// This file is converted first, so Holder's constant initializer performs the
// first fetch of A\E::X while namespace Consumer is active.
namespace Consumer {
    class Holder
    {
        public const REF = \A\E::X;
    }
}

namespace {
    function main()
    {
        var_dump(\Consumer\Holder::REF);
        var_dump(\A\E::X->value);
    }
}
