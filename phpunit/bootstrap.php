<?php

use PHPUnit\Framework\TestCase;
use PhpAot\Php\CompilerTest;
use PhpAot\Php\Exception\TestError;

require __DIR__ . '/../bin/bootstrap.php';
require __DIR__ . '/../src/gen_stub.php';

class BaseTest extends TestCase
{
    protected function exec(string $expected, string $file): void
    {
        try {
            global $translator;
            $compiler = CompilerTest::create(ROOT_PATH);
            $translator = $compiler;
            $testFile = __DIR__ . '/code/' . $file;
            $compiler->addFiles([$testFile]);
            $compiler->prepareFile($testFile);
            $compiler->convertFile($testFile);
        } catch (TestError $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage());
            return;
        }
        $this->fail();
    }
}