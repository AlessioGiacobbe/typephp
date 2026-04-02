<?php

class FunctionTest extends \BaseTest
{
    public function testReturnRef()
    {
        $this->exec('The return type of the function `test` cannot be a reference type', 'function-return-ref.php');
    }
}
