<?php

use TypePhp\CompilerTest;

/**
 * A class constant whose value is an enum case must resolve to the case
 * object at runtime, not to the folded backing value / case-name string.
 * Enum case objects have request lifetime, so the registration re-binds
 * the constant with php::updateConstant + php::getEnumCase on request init
 * and resets it on request shutdown, like array constants.
 */
final class EnumCaseClassConstantTest extends \BaseTest
{
    public function testEnumCaseConstantsAreReboundPerRequest(): void
    {
        [$arginfo, $extension] = $this->compileFixture();

        // Request init binds the real case object, following constant chains
        // and interface constants.
        self::assertStringContainsString(
            'php::updateConstant("CaseConstHolder", "CB", php::getEnumCase(php::getClassEntrySafe("CaseConstEnum"), "B"));',
            $extension,
        );
        self::assertStringContainsString(
            'php::updateConstant("CaseConstHolder", "CHAIN", php::getEnumCase(php::getClassEntrySafe("CaseConstEnum"), "B"));',
            $extension,
        );
        self::assertStringContainsString(
            'php::updateConstant("CaseConstInterface", "IC", php::getEnumCase(php::getClassEntrySafe("CaseConstEnum"), "B"));',
            $extension,
        );
        self::assertStringContainsString(
            'php::updateConstant("CaseConstHolder", "IC", php::getEnumCase(php::getClassEntrySafe("CaseConstEnum"), "B"));',
            $extension,
        );
        // Request shutdown must clear the request-bound object from the
        // persistent class entry.
        self::assertStringContainsString(
            'php::updateConstant("CaseConstHolder", "CB", php::null);',
            $extension,
        );
    }

    public function testExpressionValuedBackedCaseEvaluates(): void
    {
        [$arginfo] = $this->compileFixture();

        // `case A = 1 + 1;` must register with its evaluated backing value,
        // and the placeholder for CB must be the backing value of B, not the
        // case-name string.
        self::assertStringContainsString('ZVAL_LONG(&enum_case_A_value, 2);', $arginfo);
        self::assertStringContainsString('ZVAL_LONG(&const_CB_value, 4);', $arginfo);
        self::assertStringNotContainsString('"CB", sizeof("CB") - 1, 1);', $arginfo);
    }

    /** @return array{string, string} [arginfo, extension] */
    private function compileFixture(): array
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $source = TYPEPHP_ROOT_PATH . '/phpunit/code/enum-case-class-constant.php';
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);
        $compiler->convertFile($source);
        $arginfo = file_get_contents($compiler->getArgInfoHeaderFile($source));
        $extension = file_get_contents($compiler->genExtension());

        self::assertIsString($arginfo);
        self::assertIsString($extension);
        return [$arginfo, $extension];
    }
}
