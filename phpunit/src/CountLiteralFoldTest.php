<?php

namespace TypePhp\Tests;

use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;

class CountLiteralFoldTest extends TestCase
{
    public function testUnfoldableArrayLiteralsKeepTheRuntimeCall(): void
    {
        $cpp = $this->compileToCpp('count-literal-fold-unsafe.php');

        // Element side effects, a repeated key and a spread each make the
        // number of AST items differ from the runtime element count.
        self::assertSame(4, substr_count($cpp, 'php::fn::count('));
        self::assertStringContainsString('php_bump()', $cpp);
        self::assertStringContainsString('i++', $cpp);
    }

    public function testPlainArrayLiteralsStillFoldAtCompileTime(): void
    {
        $cpp = $this->compileToCpp('count-literal-fold-safe.php');

        self::assertStringNotContainsString('php::fn::count(', $cpp);
    }

    private function compileToCpp(string $file): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/' . $file;
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);

        return file_get_contents($compiler->convertFile($source));
    }
}
