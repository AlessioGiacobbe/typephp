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

    public function testCallAbstractParentMethod()
    {
        $this->exec('Cannot call abstract method `AbsBase::show()`', 'parent-abstract-method.php');
    }

    public function testNewAbstractClass()
    {
        $this->exec('abstract class `AbstractBase` cannot be instantiated', 'abstract-class-new.php');
    }

    public function testOverridePrivateMethod()
    {
        $this->exec('Cannot override private method `Base::doWork()`', 'override-private-method.php');
    }
}
