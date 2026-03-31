<?php


use PhpAot\Php\CompilerTest;
use PhpAot\Php\Exception\TestError;
use PHPUnit\Framework\TestCase;

class ErrorTest extends TestCase
{
    public function testDuplicateStaticVar()
    {
        try {
            $o = CompilerTest::getInstance();
            $file = __DIR__ . '/../code/duplicate.php';
            $o->addFiles([$file]);
            $o->convert($file);
        } catch (TestError $exception) {
            $this->assertStringContainsString('Duplicate static variable', $exception->getMessage());
            return;
        }
        $this->fail();
    }
}
