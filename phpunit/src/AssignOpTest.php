<?php

class AssignOpTest extends \BaseTest
{
    public function testAssignOpUndefinedVar()
    {
        $this->exec('Cannot assign to undefined variable', 'assign-op-undefined-var.php');
    }

    public function testConcatToArray()
    {
        $this->exec('Cannot concat string to array', 'assign-op-concat-array.php');
    }
}
