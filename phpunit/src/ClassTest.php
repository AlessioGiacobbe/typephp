<?php

class ClassTest extends \BaseTest
{
    public function testGetterGeneratesPublicMethodsForInstanceProperties(): void
    {
        $this->compile('getter.php');
    }

    public function testGetterRejectsStaticProperties(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Getter can only be applied to instance properties');
        $this->compile('getter-static-property.php');
    }

    public function testGetterRejectsNonPropertyTargets(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('Getter can only be applied to instance properties');
        $this->compile('getter-function.php');
    }

    public function testCompileTimeGeneratedPropertyMethodsPrinterAndNotNull(): void
    {
        $this->compile('compile_time_attributes.php');
    }

    public function testNotNullRejectsNonParameterTargets(): void
    {
        $this->expectException(\TypePhp\Exception\SyntaxError::class);
        $this->expectExceptionMessage('NotNull can only be applied to function or method parameters');
        $this->compile('not-null-invalid-target.php');
    }

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

    public function testTraitMayCallProtectedParentMethod()
    {
        // A protected parent method is reachable via parent:: from a trait,
        // matching PHP runtime behaviour.
        $this->compile('trait-parent-method-protected.php');
    }

    public function testCannotAccessPrivateParentMethodFromRegularClass()
    {
        $this->exec('Cannot access private method `Base::secret()`', 'parent-method-private.php');
    }

    public function testCannotAccessPrivateParentMethodFromTrait()
    {
        $this->exec('Cannot access private method `BaseSecret::secret()`', 'trait-parent-method-private.php');
    }

    public function testTraitMethodMayShadowPrivateParentMethod()
    {
        $this->compile('trait-method-shadows-private.php');
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

    public function testPropertyDefaultArrayForIntTypeFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use array as default value for property PropertyDefaultArrayForInt::$a of type int',
            'property-default-array-for-int.php'
        );
    }

    public function testPropertyDefaultStringForIntTypeFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use string as default value for property PropertyDefaultStringForInt::$a of type int',
            'property-default-string-for-int.php'
        );
    }

    public function testPropertyDefaultNullForNonNullableIntFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use null as default value for property PropertyDefaultNullForInt::$a of type int',
            'property-default-null-for-int.php'
        );
    }

    public function testPropertyDefaultArrayForObjectTypeFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use array as default value for property PropertyDefaultArrayForObject::$dep of type PropertyDefaultArrayForObjectDep',
            'property-default-array-for-object.php'
        );
    }

    public function testValidPropertyDefaultsCompile()
    {
        // 合法的默认值（含 int→float 协变、nullable、联合类型、mixed、常量）应通过检查。
        $this->compile('property-default-valid.php');
    }

    public function testTrueDefaultForFalsePropertyFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use true as default value for property PropertyDefaultTrueForFalse::$value of type false',
            'property-default-true-for-false.php'
        );
    }

    public function testFalseDefaultForTruePropertyFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use false as default value for property PropertyDefaultFalseForTrue::$value of type true',
            'property-default-false-for-true.php'
        );
    }

    public function testClassConstantPropertyDefaultTypeFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use string as default value for property PropertyDefaultClassConstType::$value of type int',
            'property-default-class-const-type.php'
        );
    }

    public function testExpressionPropertyDefaultTypeFailsAtCompileTime()
    {
        $this->exec(
            'Cannot use float as default value for property PropertyDefaultExpressionType::$value of type int',
            'property-default-expression-type.php'
        );
    }
}
