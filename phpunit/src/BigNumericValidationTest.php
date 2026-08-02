<?php

class BigNumericValidationTest extends \BaseTest
{
    public function testDecimalPowerOperatorIsRejected(): void
    {
        $this->exec("Operator '**' is not supported for Decimal or BigFloat", 'big-numeric/decimal-pow-operator.php');
    }

    public function testBigFloatPowerOperatorIsRejected(): void
    {
        $this->exec("Operator '**' is not supported for Decimal or BigFloat", 'big-numeric/bigfloat-pow-operator.php');
    }

    public function testDifferentBigTypesCannotBeComparedImplicitly(): void
    {
        $this->exec(
            'Cannot compare different Big* types implicitly',
            'big-numeric/mixed-big-comparison.php'
        );
    }
}
