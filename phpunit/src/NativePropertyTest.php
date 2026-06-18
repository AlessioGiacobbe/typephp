<?php

use PhpAot\Php\CompilerTest;
use PhpAot\Php\Exception\TestError;

class NativePropertyTest extends \BaseTest
{
    private function compile(string $file): void
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

    public function testFindNativePropertyUsesFullClassNameAcrossBranches(): void
    {
        try {
            $this->compile('native-property-full-name.php');
        } catch (TestError $e) {
            $this->fail($e->getMessage());
        }
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
