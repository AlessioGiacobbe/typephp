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
        $this->exec('must be compatible', 'inheritance_error_visibility.php');
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
}
