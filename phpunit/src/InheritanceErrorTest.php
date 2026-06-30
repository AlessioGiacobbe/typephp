<?php

use PhpAot\Php\CompilerTest;
use PhpAot\Php\Exception\TestError;
use PHPUnit\Framework\TestCase;

class InheritanceErrorTest extends TestCase
{
    private function exec(string $expected, string $file): void
    {
        try {
            $compiler = CompilerTest::create(ROOT_PATH);
            $testFile = __DIR__ . '/../code/' . $file;
            $compiler->addFiles([$testFile]);
            $compiler->prepareFile($testFile);
            $compiler->convertFile($testFile);
        } catch (TestError $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage());
            return;
        }
        $this->fail('Expected TestError exception was not thrown');
    }

    private function assertCompiles(string $file): void
    {
        global $translator;
        $compiler = CompilerTest::create(ROOT_PATH);
        $translator = $compiler;
        $testFile = __DIR__ . '/../code/' . $file;
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $compiler->convertFile($testFile);
        $this->addToAssertionCount(1);
    }

    public function testParameterCountMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error.php');
    }

    public function testParameterTypeMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_type.php');
    }

    public function testReturnTypeMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_return.php');
    }

    public function testByRefMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_byref.php');
    }

    public function testVariadicMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_variadic.php');
    }

    public function testMethodVisibilityMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_visibility_narrow.php');
    }

    public function testMethodVisibilityWideningIsAllowed()
    {
        $this->assertCompiles('inheritance_error_visibility.php');
    }

    public function testChildMayAddOptionalTrailingParameter()
    {
        $this->assertCompiles('inheritance_optional_param_allowed.php');
    }

    public function testPropertyTypeMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_prop_type.php');
    }

    public function testPropertyVisibilityMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_prop_visibility.php');
    }

    public function testConstantTypeMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_const_type.php');
    }

    public function testConstantVisibilityMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_const_visibility.php');
    }

    public function testPropertyReadonlyMismatch()
    {
        $this->exec('must be compatible', 'inheritance_error_prop_readonly.php');
    }

    public function testInterfaceMethodMissing()
    {
        $this->exec('must implement method', 'interface_method_missing.php');
    }

    public function testInterfaceMethodSignatureMismatch()
    {
        $this->exec('must be compatible', 'interface_method_signature_mismatch.php');
    }

    public function testInterfaceMethodProvidedByTrait()
    {
        $this->assertCompiles('interface_method_from_trait.php');
    }
}
