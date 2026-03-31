<?php


use PhpAot\Php\CompilerTest;
use PhpAot\Php\Exception\TestError;
use PHPUnit\Framework\TestCase;

class DuplicateTest extends TestCase
{
    private function exec(string $expected, string $file): void
    {
        try {
            $compiler = CompilerTest::create(ROOT_PATH);
            $testFile = __DIR__ . '/../code/' . $file;
            $compiler->addFiles([$testFile]);
            $compiler->convert($testFile);
        } catch (TestError $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage());
            return;
        }
        $this->fail();
    }

    public function testStaticVar()
    {
        $this->exec('Duplicate static variable', 'duplicate_01.php');
    }

    public function testFunction()
    {
        $this->exec('Duplicate function', 'duplicate_02.php');
    }

    public function testClass()
    {
        $this->exec('Duplicate class', 'duplicate_03.php');
    }
}
