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
            'std::unsafe_cast() expects second argument to be an UnsafePtr parameter',
            'std-unsafe-cast-requires-unsafe-ptr.php'
        );
    }

    public function testStdUnsafeCastRejectsUnsafePtrLocalCopy()
    {
        $this->exec(
            'std::unsafe_cast() expects second argument to be an UnsafePtr parameter',
            'std-unsafe-cast-rejects-unsafe-ptr-local-copy.php'
        );
    }

    public function testStdUnsafePtrParameterCannotBeReassigned()
    {
        $this->exec(
            'Cannot re-assign UnsafePtr parameter `$unsafePtr`',
            'std-unsafe-ptr-parameter-cannot-be-reassigned.php'
        );
    }

    public function testStdUnsafePtrArgumentRequiresContainer()
    {
        $this->exec(
            'Argument `unsafePtr` must be a std container variable for UnsafePtr parameter',
            'std-unsafe-ptr-argument-requires-container.php'
        );
    }
}
