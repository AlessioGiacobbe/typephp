<?php

class OperatorTest extends \BaseTest
{
    public function testLiteralIntDivideByZeroDoesNotCompile(): void
    {
        $this->exec('Cannot divide or modulo by zero', 'divide-by-zero-int.php');
    }

    public function testLiteralFloatDivideByZeroDoesNotCompile(): void
    {
        $this->exec('Cannot divide or modulo by zero', 'divide-by-zero-float.php');
    }

    public function testLiteralStringDivideByZeroDoesNotCompile(): void
    {
        $this->exec('Cannot divide or modulo by zero', 'divide-by-zero-string.php');
    }

    public function testLiteralModuloByZeroDoesNotCompile(): void
    {
        $this->exec('Cannot divide or modulo by zero', 'modulo-by-zero-int.php');
    }

    public function testLiteralDivideAssignByZeroDoesNotCompile(): void
    {
        $this->exec('Cannot divide or modulo by zero', 'assign-divide-by-zero.php');
    }

    public function testLiteralModuloAssignByZeroDoesNotCompile(): void
    {
        $this->exec('Cannot divide or modulo by zero', 'assign-modulo-by-zero.php');
    }
}
