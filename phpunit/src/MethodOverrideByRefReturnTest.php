<?php

use TypePhp\Exception\TestError;

/**
 * Zend treats by-ref returns as covariant in overrides: a child method may add
 * `&` to its return (callers expecting a value still work), but it must not
 * drop a by-ref return promised by the parent contract.
 */
class MethodOverrideByRefReturnTest extends BaseTest
{
    public function testOverrideMayAddByRefReturn(): void
    {
        $this->compile('override_byref_return_added.php');
    }

    public function testOverrideCannotDropByRefReturn(): void
    {
        $this->exec(
            'Declaration of `B::f()` must be compatible with `A::f()`',
            'override_byref_return_dropped.php',
        );
    }
}
