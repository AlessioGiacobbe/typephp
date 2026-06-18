<?php

use PhpAot\Php\CompilerTest;
use PhpAot\Php\Exception\TestError;

class NativePropertyTest extends \BaseTest
{
    private function compile(string $file): string
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
            $this->compile('native-property-full-name.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }
    }

    public function testStaticStaticPropertyUsesDynamicCalledClassPath(): void
    {
        try {
            $outputFile = $this->compile('native-property-full-name.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }

        $code = file_get_contents($outputFile);
        $this->assertStringContainsString('php::getStaticProperty(php_get_called_class(this_), "count")', $code);
        $this->assertStringContainsString('php::getStaticProperty(php_get_called_class(this_), "count") = php::toInt(value)', $code);
    }

    public function testCannotAccessPrivateNativePropertyFromUnrelatedClass(): void
    {
        $this->exec('Cannot access private property `value` of class `NativePrivateOwner`', 'native-property-private-other-class.php');
    }

    public function testCannotAccessProtectedNativePropertyFromUnrelatedClass(): void
    {
        $this->exec('Cannot access protected property `value` of class `NativeProtectedOwner`', 'native-property-protected-unrelated-class.php');
    }
}
