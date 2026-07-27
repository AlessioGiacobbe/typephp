--TEST--
Unqualified runtime constants in a namespace fall back to global constants
--FILE--
<?php

namespace RuntimeConstantFallback {
    function readGlobalOnly()
    {
        return GLOBAL_ONLY;
    }

    function readPreferred()
    {
        return PREFERRED;
    }

    function readInternalOverride()
    {
        return PHP_VERSION;
    }
}

namespace {
    function main(): void
    {
        define('GLOBAL_ONLY', 'global');
        define('PREFERRED', 'global-preferred');
        define('RuntimeConstantFallback\Preferred', 'wrong-case-name');
        define('RuntimeConstantFallback\PREFERRED', 'namespaced');
        define('RuntimeConstantFallback\PHP_VERSION', 'runtime-override');

        var_dump(\RuntimeConstantFallback\readGlobalOnly());
        var_dump(\RuntimeConstantFallback\readPreferred());
        var_dump(\RuntimeConstantFallback\readInternalOverride());
    }
}
?>
--EXPECT--
string(6) "global"
string(10) "namespaced"
string(16) "runtime-override"
