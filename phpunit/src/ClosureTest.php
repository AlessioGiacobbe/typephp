<?php

use TypePhp\CompilerTest;

class ClosureTest extends \PHPUnit\Framework\TestCase
{
    public function testUseReferenceCaptureCompiles(): void
    {
        global $translator;

        $testFile = ROOT_PATH . '/phpunit/code/closure/use-reference-capture.php';
        $compiler = CompilerTest::create(ROOT_PATH);
        $translator = $compiler;
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $compiler->convertFile($testFile);

        $this->assertTrue(true);
    }
}
