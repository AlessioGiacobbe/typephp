<?php

class UndefineTest extends \BaseTest
{
    public function testUnset()
    {
        $this->exec('The variable `$u1` is undefined', 'undefined-vars-01.php');
        $this->exec('Unsupported unset type `Expr_FuncCall`', 'unset-01.php');
    }
}
