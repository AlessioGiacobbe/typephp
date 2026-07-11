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

    public function testCannotWritePrivateSetPropertyOutsideDeclaringClass()
    {
        $this->exec('Cannot modify private(set) property', 'private-set-property.php');
    }

    public function testCannotWriteProtectedSetPropertyOutsideClassFamily()
    {
        $this->exec('Cannot modify protected(set) property', 'protected-set-property.php');
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
        $compiler = \TypePhp\CompilerTest::create(ROOT_PATH);
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
        $compiler = \TypePhp\CompilerTest::create(ROOT_PATH);
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

    public function testConstructorCannotDeclareReturnType()
    {
        $this->exec('Method `ConstructorReturnType::__construct()` cannot declare a return type', 'constructor-return-type.php');
    }

    public function testConstructorCannotReturnValue()
    {
        $this->exec('Method `ConstructorReturnValue::__construct()` cannot return a value', 'constructor-return-value.php');
    }

    public function testParentConstructorCannotBeUsedAsValue()
    {
        $this->compile('parent-constructor-used-as-value.php');
    }

    public function testParentConstructorCannotBeUsedAsArgument()
    {
        $this->compile('parent-constructor-used-as-argument.php');
    }

    public function testVoidExpressionCannotBeUsedAsBinaryOperand()
    {
        $this->compile('void-expression-binary-operand.php');
    }

    public function testVoidExpressionCannotBeUsedAsCondition()
    {
        $this->compile('void-expression-condition.php');
    }

    public function testVoidExpressionCannotBeUsedAsTernaryBranch()
    {
        $this->compile('void-expression-ternary-branch.php');
    }

    public function testVoidExpressionCannotBeUsedAsArrayValue()
    {
        $this->compile('void-expression-array-value.php');
    }

    public function testVoidExpressionCannotBeUsedAsMatchArm()
    {
        $this->compile('void-expression-match-arm.php');
    }

    public function testDestructorCannotDeclareReturnType()
    {
        $this->exec('Method `DestructorReturnType::__destruct()` cannot declare a return type', 'destructor-return-type.php');
    }

    public function testCloneReturnTypeMustBeVoid()
    {
        $this->exec('Method `CloneInvalidReturnType::__clone()` return type must be void when declared', 'clone-invalid-return-type.php');
    }

    public function testCallMagicMethodCannotBeStatic()
    {
        $this->exec('Method MagicCallStaticInvalid::__call() cannot be static', 'magic-call-static.php');
    }

    public function testCallStaticMagicMethodMustBeStatic()
    {
        $this->exec('Method MagicCallStaticNonStaticInvalid::__callStatic() must be static', 'magic-callstatic-nonstatic.php');
    }

    public function testToStringMagicMethodCannotTakeArguments()
    {
        $this->exec('Method MagicToStringArgsInvalid::__toString() must take exactly 0 arguments', 'magic-tostring-args.php');
    }

    public function testSetStateMagicMethodMustBeStatic()
    {
        $this->exec('Method MagicSetStateNonStaticInvalid::__set_state() must be static', 'magic-set-state-nonstatic.php');
    }

    public function testDestructMagicMethodCannotTakeArguments()
    {
        $this->exec('Method MagicDestructArgsInvalid::__destruct() must take exactly 0 arguments', 'magic-destruct-args.php');
    }

    public function testGetMagicMethodParameterMustBeString()
    {
        $this->exec('Method MagicGetParamTypeInvalid::__get() must take string as argument', 'magic-get-param-type.php');
    }

    public function testMagicMethodMustBePublic()
    {
        $this->exec('Method MagicGetProtectedInvalid::__get() must have public visibility', 'magic-get-protected.php');
    }

    public function testClassConstDefaultValue()
    {
        // 类常量（self:: / 类名:: / 完全限定名::，含继承自内部父类的常量）
        // 作为函数/方法默认参数值应当能够在编译期正确解析。
        $this->compile('class-const-default-value.php');
    }
}
