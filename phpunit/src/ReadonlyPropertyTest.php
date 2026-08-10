<?php

class ReadonlyPropertyTest extends \BaseTest
{
    public function testConstructorCannotBeCalledAsOrdinaryMethod(): void
    {
        $this->exec('Constructor __construct() can only be invoked by new', 'constructor-direct-method-call.php');
        $this->exec('Constructor __construct() can only be invoked by new', 'constructor-direct-static-call.php');
    }

    public function testCloneCannotBeCalledAsOrdinaryMethod(): void
    {
        $this->exec('Clone method __clone() can only be invoked by clone', 'clone-direct-method-call.php');
        $this->exec('Clone method __clone() can only be invoked by clone', 'clone-direct-static-call.php');
    }

    public function testWriteOutsideConstructorIsRejected(): void
    {
        $this->exec('Readonly property `ReadonlyWriteOutsideConstructor::$value` can only be modified in its declaring `__construct` or `__clone` method', 'readonly-write-outside-constructor.php');
    }

    public function testChildConstructorCannotWriteParentReadonlyProperty(): void
    {
        $this->exec('Readonly property `ReadonlyParent::$value` can only be modified in its declaring `__construct` or `__clone` method', 'readonly-write-child-constructor.php');
    }

    public function testConstructorCannotWriteReadonlyPropertyOnAnotherObject(): void
    {
        $this->exec('Readonly property `ReadonlyOtherInstance::$value` can only be modified on `$this`', 'readonly-write-other-instance-constructor.php');
    }

    public function testClosureInsideConstructorCannotWriteReadonlyProperty(): void
    {
        $this->exec('Readonly property `ReadonlyConstructorClosure::$value` can only be modified directly in `__construct` or `__clone`', 'readonly-write-constructor-closure.php');
    }

    public function testReadonlyCloneWriteRetainsLexicalRestrictions(): void
    {
        $this->exec(
            'Readonly property `ReadonlyCloneParent::$value` can only be modified in its declaring `__construct` or `__clone` method',
            'readonly-write-child-clone.php'
        );
        $this->exec(
            'Readonly property `ReadonlyCloneClosure::$value` can only be modified directly in `__construct` or `__clone`',
            'readonly-write-clone-closure.php'
        );
        $this->exec(
            'Readonly property `ReadonlyCloneOtherInstance::$value` can only be modified on `$this`',
            'readonly-write-other-instance-clone.php'
        );
    }

    public function testReadonlyPropertyCannotBeAssignedByReference(): void
    {
        $this->exec('Cannot assign readonly property `ReadonlyReferenceAssignment::$value` by reference', 'readonly-reference-assignment.php');
    }

    public function testReadonlyPropertyCannotBeTakenByReference(): void
    {
        $this->exec('Cannot take reference to readonly property `ReadonlyReferenceFetch::$value`', 'readonly-reference-fetch.php');
        $this->exec('Cannot take reference to readonly property `ReadonlyReferenceCallArgument::$value`', 'readonly-reference-call-argument.php');
    }

    public function testAllReadonlyWriteFormsOutsideConstructorAreRejected(): void
    {
        foreach ([
            'readonly-write-compound.php',
            'readonly-write-increment.php',
            'readonly-write-array-dim.php',
            'readonly-write-coalesce.php',
            'readonly-write-list.php',
            'readonly-write-foreach.php',
        ] as $file) {
            $this->exec('can only be modified in its declaring `__construct` or `__clone` method', $file);
        }
    }
}
