<?php

class ClassTest extends \BaseTest
{
    public function testReAssignThis()
    {
        $this->exec('Cannot re-assign $this', 're-assign-this.php');
    }

    public function testAccessProtectedProperty()
    {
        $this->exec('Cannot access protected property `settings` of class `DevConfig`', 'protected-property.php');
    }
}
