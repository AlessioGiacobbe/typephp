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

    public function testSelfCanBePartOfUnionType()
    {
        global $translator;
        $compiler = \PhpAot\Php\CompilerTest::create(ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/union_type_self_allowed.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $compiler->convertFile($testFile);
        $this->addToAssertionCount(1);
    }

    public function testParentCanBePartOfUnionType()
    {
        global $translator;
        $compiler = \PhpAot\Php\CompilerTest::create(ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/union_type_parent_allowed.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $compiler->convertFile($testFile);
        $this->addToAssertionCount(1);
    }

    public function testSelfCannotBePartOfIntersectionType()
    {
        $this->exec("Type 'self' cannot be part of an intersection type", 'intersection_type_self_not_allowed.php');
    }

    public function testParentCannotBePartOfIntersectionType()
    {
        $this->exec("Type 'parent' cannot be part of an intersection type", 'intersection_type_parent_not_allowed.php');
    }

    public function testStaticCannotBePartOfIntersectionType()
    {
        $this->exec("Type 'static' cannot be part of an intersection type", 'intersection_type_static_not_allowed.php');
    }
}
