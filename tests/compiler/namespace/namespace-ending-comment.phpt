--TEST--
A namespace block ending with a comment must not be treated as stray code
--FILE--
<?php

declare(strict_types=1);

namespace Test {
    // test
}

namespace {
    function main()
    {
        var_dump('done');
    }

    // test1
}
?>
--EXPECT--
string(4) "done"
