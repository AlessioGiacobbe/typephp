<?php

class AssignTest extends \BaseTest
{
    public function testReAssign()
    {
        $this->exec('Cannot re-assign variable', 're-assign.php');
    }

    public function testAssignClass()
    {
        $this->exec('Cannot re-assign typed object `$obj1` from `stdClass` to `ArrayObject`', 're-assign-2.php');
    }

    public function testStdContainerStaticClassMismatch()
    {
        $this->exec(
            'Cannot assign object of class `StdContainerStaticChild` to std container value of class `StdContainerStaticBase`',
            'std-container-static-class-mismatch.php'
        );
    }

    public function testStdUnsafeCastRequiresUnsafePtr()
    {
        $this->exec(
            'std::unsafe_cast() expects second argument to be declared as UnsafePtr',
            'std-unsafe-cast-requires-unsafe-ptr.php'
        );
    }
}
