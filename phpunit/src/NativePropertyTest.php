<?php

use PhpAot\Php\CompilerTest;
use PhpAot\Php\Exception\TestError;

class NativePropertyTest extends \BaseTest
{
    private function compileNativeProperty(string $file): string
    {
        global $translator;

        $compiler = CompilerTest::create(ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/' . $file;
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $compiler->convertFile($testFile);

        $this->addToAssertionCount(1);
        return ROOT_PATH . '/build/phpunit/code/' . basename($file, '.php') . '.cc';
    }

    public function testFindNativePropertyUsesFullClassNameAcrossBranches(): void
    {
        try {
            $this->compileNativeProperty('native-property-full-name.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }
    }

    public function testStaticStaticPropertyUsesDynamicCalledClassPath(): void
    {
        try {
            $outputFile = $this->compileNativeProperty('native-property-full-name.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringContainsString('php::getStaticProperty(php_get_called_class(this_), "count")', $code);
        $this->assertStringContainsString('php::getStaticProperty(php_get_called_class(this_), "count") = php::toInt(value)', $code);
    }

    public function testNativeIntPropertyAssignOpUsesNativeReference(): void
    {
        try {
            $outputFile = $this->compileNativeProperty('native-property-assign-op-int.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringContainsString('php_aot_static_int_ref(this_.attr(', $code);
        $this->assertStringContainsString('php_aot_static_int_ref(box.attr(', $code);
        $this->assertSame(2, substr_count($code, 'php_aot_static_int_ref('));
        $this->assertStringNotContainsString('this_.attr(php_get_prop(0, _literal_strings[0], 0, _literal_strings[1]), true) +=', $code);
        $this->assertStringNotContainsString('box.attr(php_get_prop(0, _literal_strings[0], 0, _literal_strings[1]), true) +=', $code);
    }

    public function testCannotAccessPrivateNativePropertyFromUnrelatedClass(): void
    {
        $this->exec('Cannot access private property `value` of class `NativePrivateOwner`', 'native-property-private-other-class.php');
    }

    public function testCannotAccessProtectedNativePropertyFromUnrelatedClass(): void
    {
        $this->exec('Cannot access protected property `value` of class `NativeProtectedOwner`', 'native-property-protected-unrelated-class.php');
    }

    public function testCannotAccessPrivateNativePropertyThroughNullsafe(): void
    {
        $this->exec('Cannot access private property `value` of class `NullsafePrivateOwner`', 'nullsafe-private-property.php');
    }

    public function testCannotAccessNestedPrivateNativePropertyThroughNullsafe(): void
    {
        $this->exec('Cannot access private property `value` of class `NullsafeNestedChild`', 'nullsafe-nested-private-property.php');
    }

    public function testCannotAssignThroughNullsafeProperty(): void
    {
        $this->exec("Can't use nullsafe operator in write context", 'nullsafe-write-assign.php');
    }

    public function testCannotUseCompoundAssignThroughNullsafeProperty(): void
    {
        $this->exec("Can't use nullsafe operator in write context", 'nullsafe-write-assign-op.php');
    }

    public function testCannotIncrementThroughNullsafeProperty(): void
    {
        $this->exec("Can't use nullsafe operator in write context", 'nullsafe-write-inc.php');
    }

    public function testCannotUnsetThroughNullsafeProperty(): void
    {
        $this->exec("Can't use nullsafe operator in write context", 'nullsafe-write-unset.php');
    }

    public function testCannotAssignReferenceToNullsafeProperty(): void
    {
        $this->exec("Can't use nullsafe operator in write context", 'nullsafe-write-assign-ref-left.php');
    }

    public function testCannotTakeReferenceOfNullsafeProperty(): void
    {
        $this->exec('Cannot take reference of a nullsafe chain', 'nullsafe-write-assign-ref-right.php');
    }
}
