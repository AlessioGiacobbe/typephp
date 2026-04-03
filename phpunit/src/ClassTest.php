<?php

class ClassTest extends \BaseTest
{
    public function testReAssignThis()
    {
        $this->exec('Cannot re-assign $this', 're-assign-this.php');
    }

}
