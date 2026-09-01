<?php

/**
 * Readonly-ness is part of the inheritance contract in both directions, and
 * an interface can only extend other interfaces.
 */
class ClassKindInheritanceTest extends BaseTest
{
    public function testNonReadonlyCannotExtendReadonly(): void
    {
        $this->exec(
            'Non-readonly class `B` cannot extend readonly class `A`',
            'readonly_class_extends.php'
        );
    }

    public function testReadonlyCannotExtendNonReadonly(): void
    {
        $this->exec(
            'Readonly class `B` cannot extend non-readonly class `A`',
            'readonly_class_extends_rev.php'
        );
    }


    public function testReadonlyExtendsReadonlyIsValid(): void
    {
        $this->compile('readonly_class_extends_valid.php');
    }
}
