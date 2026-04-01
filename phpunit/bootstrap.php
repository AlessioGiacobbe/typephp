<?php

use PHPUnit\Framework\TestCase;
use PhpAot\Php\CompilerTest;
use PhpAot\Php\Exception\TestError;

require __DIR__ . '/../bin/bootstrap.php';

class BaseTest extends TestCase
{
    protected function exec(string $expected, string $file): void
    {
        try {
            $compiler = CompilerTest::create(ROOT_PATH);
            $testFile = __DIR__ . '/code/' . $file;
            $compiler->addFiles([$testFile]);
            $compiler->prepare($testFile);
            $compiler->convert($testFile);
        } catch (TestError $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage());
            return;
        }
        $this->fail();
    }
}