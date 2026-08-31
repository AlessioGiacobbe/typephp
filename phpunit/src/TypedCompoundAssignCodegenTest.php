<?php

use TypePhp\CompilerTest;

/**
 * Compound assignment and ++/-- on native int slots must be lowered to the
 * equivalent plain assignment through the PHP-semantics binary operators:
 * raw C++ `a += b` has undefined signed overflow (PHP promotes to float),
 * `a /= b` truncates and misses DivisionByZeroError, `a %= 0` and
 * out-of-range shifts are UB, and `a++` at PHP_INT_MAX is UB.
 */
final class TypedCompoundAssignCodegenTest extends \BaseTest
{
    public function testIntCompoundOpsAreLoweredToPlainAssignments(): void
    {
        $code = $this->compileFixture();

        self::assertStringContainsString('a = php::toInt(((php::Var(a)) + (php::Var(b))))', $code);
        self::assertStringContainsString('a = php::toInt(((php::Var(a)) / (php::Var(b))))', $code);
        self::assertStringContainsString('a = php::toInt(php::fn::mod(a, b))', $code);
        self::assertStringContainsString('a = php::toInt(((php::Var(a)) << (php::Var(b))))', $code);
        self::assertStringNotContainsString('a += ', $code);
        self::assertStringNotContainsString('a /= ', $code);
        self::assertStringNotContainsString('a %= ', $code);
        self::assertStringNotContainsString('a <<= ', $code);
    }

    public function testPostIncrementKeepsOldValueThroughNativeTemporary(): void
    {
        $code = $this->compileFixture();

        self::assertMatchesRegularExpression(
            '/\(tmp_var_\d+ = a, a = php::toInt\(\(\(php::Var\(a\)\) \+ \(php::Var\(1L{1,2}\)\)\)\), tmp_var_\d+\)/',
            $code,
        );
        self::assertStringNotContainsString('a++', $code);
    }

    public function testPreDecrementIsLoweredToPlainAssignment(): void
    {
        $code = $this->compileFixture();

        self::assertMatchesRegularExpression('/\(a = php::toInt\(\(\(php::Var\(a\)\) - \(php::Var\(1L{1,2}\)\)\)\)\)/', $code);
        self::assertStringNotContainsString('--a', $code);
    }

    public function testWellDefinedBitwiseCompoundStaysRaw(): void
    {
        $code = $this->compileFixture();

        self::assertStringContainsString('a &= ', $code);
    }

    private function compileFixture(): string
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/typed-compound-assign-codegen.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        return $code;
    }
}
