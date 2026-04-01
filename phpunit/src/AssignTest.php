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
}
