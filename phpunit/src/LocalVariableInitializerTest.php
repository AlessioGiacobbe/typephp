<?php

use TypePhp\CompilerTest;

final class LocalVariableInitializerTest extends \BaseTest
{
    public function testTopLevelLiteralAssignmentsInitializeDeclarations(): void
    {
        global $translator;

        $compiler = CompilerTest::create(ROOT_PATH);
        $translator = $compiler;
        $source = ROOT_PATH . '/phpunit/code/local-literal-declaration-initializer.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $generated = $compiler->convertFile($source);
        $code = file_get_contents($generated);

        self::assertIsString($code);
        self::assertStringContainsString('php::Var integer = 42L;', $code);
        self::assertStringContainsString('php::Var negative = -7L;', $code);
        self::assertStringContainsString('php::Var floating = 1.25;', $code);
        self::assertStringContainsString('php::Var boolean = true;', $code);
        self::assertMatchesRegularExpression('/php::Str string = _literal_strings\[\d+\];/', $code);
        self::assertStringContainsString('php::Var nullValue = php::null;', $code);

        self::assertStringContainsString('php::Var nested;', $code);
        self::assertStringContainsString('nested = 9L;', $code);
        self::assertStringContainsString('php::Var computed;', $code);
        self::assertStringContainsString('computed = ((40L) + (2L));', $code);

        $afterDeclaration = substr(
            $code,
            strpos($code, 'php::Var integer = 42L;') + strlen('php::Var integer = 42L;'),
        );
        self::assertStringNotContainsString('integer = 42L;', $afterDeclaration);
    }
}
