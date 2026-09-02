<?php

use TypePhp\CompilerTest;

/**
 * Lazy evaluation of backed enum case value expressions (kept as ASTs during
 * prepare, evaluated on first convert-phase access): cycle detection matching
 * Zend's "Cannot declare self-referencing constant", and name resolution
 * against the enum's own declaration context rather than whatever namespace
 * the translator is converting when the first access happens.
 */
class EnumCaseExprEvaluationTest extends BaseTest
{
    public function testSelfReferencingCaseIsRejected(): void
    {
        $this->exec('Cannot declare self-referencing constant `E::A`', 'enum_case_self_reference.php');
    }

    public function testMutuallyRecursiveCasesAreRejected(): void
    {
        // Zend reports the first constant fetched twice while walking the
        // cycle (E::B for `case A = E::B; case B = E::A;`, probed on 8.4.13),
        // not the case whose evaluation started the walk.
        $this->exec('Cannot declare self-referencing constant `E::B`', 'enum_case_mutual_reference.php');
    }

    public function testCaseExprResolvesInDeclaringNamespace(): void
    {
        // Verified against Zend 8.4.13: B\Holder::REF and A\E::X->value are
        // both 21 (A\Helper::V + 1); the decoy B\Helper::V is 999.
        [$stub] = $this->convertFiles(['enum_case_cross_namespace.php']);
        self::assertStringContainsString('ZVAL_LONG(&enum_case_X_value, 21)', $stub);
        self::assertStringContainsString('ZVAL_LONG(&const_REF_value, 21)', $stub);
        self::assertStringNotContainsString('1000', $stub);
    }

    public function testCaseExprResolvesAcrossFiles(): void
    {
        // The referencing file converts first, so the lazy evaluation of
        // A\E::X runs while namespace Consumer is active; Provider inside the
        // case expression must still resolve through the declaring file's
        // `use Lib\Provider`. Verified against Zend 8.4.13: both values are 42.
        [$ref, $def] = $this->convertFiles([
            'enum_case_cross_file_ref.php',
            'enum_case_cross_file_def.php',
        ]);
        self::assertStringContainsString('ZVAL_LONG(&const_REF_value, 42)', $ref);
        self::assertStringContainsString('ZVAL_LONG(&enum_case_X_value, 42)', $def);
    }

    /**
     * Compile the given phpunit/code files as one program and return each
     * file's generated stub registration code (where constant and enum case
     * values are emitted), in argument order — conversion happens in that
     * order, which the cross-context tests rely on.
     *
     * @param list<string> $files
     * @return list<string>
     */
    private function convertFiles(array $files): array
    {
        global $translator;

        $compiler = CompilerTest::create(TYPEPHP_ROOT_PATH);
        $translator = $compiler;
        $paths = array_map(static fn (string $file): string => TYPEPHP_ROOT_PATH . '/phpunit/code/' . $file, $files);
        $compiler->addFiles($paths);
        foreach ($paths as $path) {
            $compiler->prepareFile($path);
        }
        $generated = [];
        foreach ($paths as $path) {
            $compiler->convertFile($path);
            $generated[] = file_get_contents($compiler->getArgInfoHeaderFile($path));
        }
        return $generated;
    }
}
