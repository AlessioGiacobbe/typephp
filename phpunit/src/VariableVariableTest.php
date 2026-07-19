<?php

class VariableVariableTest extends \BaseTest
{
    public function testVariableVariableWithArrayDimThrowsUnsupportedError(): void
    {
        $this->exec('The `$$` syntax is not supported', 'variable-variable-arraydim.php');
    }
}
