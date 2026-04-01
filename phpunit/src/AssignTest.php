<?php

class AssignTest extends \BaseTest
{
    public function testReAssign()
    {
        $this->exec('Cannot re-assign variable', 're-assign.php');
    }
}
