<?php

use TypePhp\CompilerTest;

final class HotPathCodegenTest extends \BaseTest
{
    public function testKnownArrayStatementWritesAvoidResultTemporaries(): void
    {
        $code = $this->compileFixture();

        self::assertStringContainsString('items.item(0L, true) = value;', $code);
        self::assertStringContainsString('items.append(value);', $code);
        self::assertStringContainsString('items.item(0L, true) += value;', $code);
        self::assertStringContainsString('items.item(0L, true) += other.item(0L, false);', $code);
        self::assertStringContainsString('items.item(2L, true) = other.item(0L, false);', $code);
        self::assertStringContainsString('items.offsetSet(0L,', $code);
    }

    public function testSafeTwoOperandConcatAndExactStringArgumentStayUnboxed(): void
    {
        $code = $this->compileFixture();

        self::assertMatchesRegularExpression(
            '/php::concat\(_literal_strings\[\d+\], limit\)/',
            $code,
        );
        self::assertStringContainsString('php::concat({', $code);
        self::assertStringNotContainsString('php::fn::strlen(php::toString(php::concat(', $code);
    }

    public function testNativePostDecrementConditionUsesNativeTemporary(): void
    {
        $code = $this->compileFixture();

        self::assertMatchesRegularExpression('/php::Int (tmp_var_\d+) = 0;[\s\S]*?\\1 = php::toInt\(limit--\);/', $code);
        self::assertDoesNotMatchRegularExpression('/php::Var (tmp_var_\d+);[\s\S]*?\\1 = limit--;/', $code);
    }

    private function compileFixture(): string
    {
        global $translator;

        $compiler = CompilerTest::create(ROOT_PATH);
        $translator = $compiler;
        $source = ROOT_PATH . '/phpunit/code/hot-path-codegen.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        return $code;
    }
}
