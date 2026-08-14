<?php

namespace TypePhp\Tests\NativeClass;

use TypePhp\Exception\TestError;

final class NativeClassValidationTest extends \BaseTest
{
    public function testRejectsUntypedProperty(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class properties must declare a type');
        $this->compile('native-class-untyped-property.php');
    }

    public function testRejectsStaticProperty(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class static properties are not supported');
        $this->compile('native-class-static-property.php');
    }

    public function testRejectsInheritanceAcrossObjectModels(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native and ZendVM-backed classes cannot inherit from each other');
        $this->compile('native-class-zend-inheritance.php');
    }

    public function testRejectsStaticMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class static methods are not supported');
        $this->compile('native-class-static-method.php');
    }

    public function testRejectsReadonlyPropertyUntilNativeWriteStateIsImplemented(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class readonly properties are not supported');
        $this->compile('native-class-readonly-property.php');
    }

    public function testRejectsStdContainerProperty(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class properties cannot use Std Container types');
        $this->compile('native-class-std-container-property.php');
    }

    public function testRejectsDynamicInstanceofBecauseNativeClassesHaveNoRuntimeTypeLookup(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Dynamic instanceof is not supported for native objects');
        $this->compile('native-class-instanceof.php');
    }

    public function testRejectsNativeObjectStoredInPhpArray(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be stored in PHP arrays');
        $this->compile('native-class-php-array.php');
    }

    public function testRejectsNativeObjectStoredInPhpObjectProperty(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be stored in PHP arrays, PHP object properties');
        $this->compile('native-class-php-property.php');
    }

    public function testRejectsNativeTypedPropertyOnZendObject(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native object types can only be used as properties of native classes');
        $this->compile('native-class-zend-native-property.php');
    }

    public function testRejectsNativeObjectCapturedByClosure(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be captured by Zend closures');
        $this->compile('native-class-closure-capture.php');
    }

    public function testRejectsNativeObjectClosureParameter(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Zend closures cannot declare native object parameters or return types');
        $this->compile('native-class-closure-parameter.php');
    }

    public function testRejectsNativeObjectReturnedByUntypedClosure(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Zend closures cannot return native objects');
        $this->compile('native-class-closure-return.php');
    }

    public function testRejectsNativeObjectParameterOnZendConstructor(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Zend-backed constructors cannot accept or return native objects');
        $this->compile('native-class-zend-constructor.php');
    }

    public function testRejectsUnsupportedNativeObjectUnion(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native object types cannot be combined with other union or intersection members');
        $this->compile('native-class-union-signature.php');
    }

    public function testRejectsIncorrectNativeKeywordReturnType(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('must return exactly `array`');
        $this->compile('native-class-keyword-return-type.php');
    }

    public function testRejectsMissingNativeKeywordMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('must define `toInt()`');
        $this->compile('native-class-keyword-missing.php');
    }

    public function testRejectsNativeObjectPassedToJsonEncode(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot cross a dynamic PHP/ZendVM call boundary');
        $this->compile('native-class-json-encode.php');
    }

    public function testRejectsNativeObjectFromUntypedReturn(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native object return values require an explicit native class return type');
        $this->compile('native-class-untyped-return.php');
    }

    public function testRejectsNativeObjectFromMixedReturn(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native object return values require an explicit native class return type');
        $this->compile('native-class-mixed-return.php');
    }

    public function testRejectsNativeObjectPassedToInterfaceParameter(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be converted to interface `NativeInterfaceArgumentContract`');
        $this->compile('native-class-interface-argument.php');
    }

    public function testRejectsNativeObjectAssignedToInterfaceVariable(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be assigned to interface-typed variables');
        $this->compile('native-class-interface-assignment.php');
    }

    public function testRejectsNativeObjectAssignedToInterfaceProperty(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be assigned to interface-typed properties');
        $this->compile('native-class-interface-property.php');
    }

    public function testRejectsNativeObjectReturnedAsInterface(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native objects cannot be returned as interface `NativeInterfaceReturnContract`');
        $this->compile('native-class-interface-return.php');
    }

    public function testRejectsDynamicMagicMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support dynamic magic method `__get()`');
        $this->compile('native-class-dynamic-magic-method.php');
    }

    public function testRejectsDynamicStaticMagicMethodBeforeGenericStaticDiagnostic(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support dynamic magic method `__callStatic()`');
        $this->compile('native-class-dynamic-static-magic-method.php');
    }

    public function testRejectsDynamicMagicMethodInjectedByTrait(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native classes do not support dynamic magic method `__serialize()`');
        $this->compile('native-class-trait-dynamic-magic-method.php');
    }

    public function testDynamicMagicMethodDenyListIsComplete(): void
    {
        $trait = new \ReflectionClass(\TypePhp\NativeClass\NativeClassSupportTrait::class);
        $constant = $trait->getReflectionConstant('UNSUPPORTED_NATIVE_MAGIC_METHODS');
        $this->assertNotFalse($constant);
        $this->assertSame([
            '__call',
            '__callstatic',
            '__get',
            '__set',
            '__isset',
            '__unset',
            '__sleep',
            '__wakeup',
            '__serialize',
            '__unserialize',
            '__set_state',
            '__debuginfo',
        ], array_keys($constant->getValue()));
    }

    public function testRejectsStaticMethodInjectedByTrait(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('Native class static methods are not supported');
        $this->compile('native-class-trait-static-method.php');
    }

    public function testRejectsMissingInternalInterfaceMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('must implement method `Countable::count()`');
        $this->compile('native-class-internal-interface-missing-method.php');
    }

    public function testRejectsNonPublicInternalInterfaceMethod(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('must be compatible with `Countable::count()`');
        $this->compile('native-class-internal-interface-visibility.php');
    }

    public function testRejectsNarrowedInternalInterfaceParameter(): void
    {
        $this->expectException(TestError::class);
        $this->expectExceptionMessage('must be compatible with `ArrayAccess::offsetExists()`');
        $this->compile('native-class-internal-interface-parameter.php');
    }
}
