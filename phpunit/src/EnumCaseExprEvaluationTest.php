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

    public function testCaseValueMayFetchEnumCaseProperties(): void
    {
        // A backed case value may fetch `->value` and `->name` of another
        // case (PHP 8.2 "fetch properties in const expressions"). Verified
        // against Zend 8.4.13: E::Two->value is 2 (E::One->value + 1) and
        // S::B->value is "A!" (S::A->name . '!').
        [$stub] = $this->convertFiles(['enum_case_property_fetch.php']);
        self::assertStringContainsString('ZVAL_LONG(&enum_case_Two_value, 2)', $stub);
        self::assertStringContainsString('enum_case_B_value_str = zend_string_init_interned("A!"', $stub);
    }

    public function testCaseNamesAreCaseSensitive(): void
    {
        // Enum class names are case-insensitive but case names are not: `A`
        // and `a` are distinct cases with a dependency between their values.
        // Resolving Holder::REF holds F::A and F::a in the in-progress guard
        // at once — a cycle key that lowercased the case name reported a
        // false "self-referencing constant F::a" here. Verified against Zend
        // 8.4.13: Holder::REF is enum(F::A), F::A->value 2, F::a->value 3.
        [$stub] = $this->convertFiles(['enum_case_sensitive_names.php']);
        self::assertStringContainsString('ZVAL_LONG(&enum_case_A_value, 2)', $stub);
        self::assertStringContainsString('ZVAL_LONG(&enum_case_a_value, 3)', $stub);
        self::assertStringContainsString('const_REF_value_case_name = zend_string_init_interned("A"', $stub);
    }

    public function testSelfReferenceThroughPropertyFetchIsRejected(): void
    {
        // A true cycle through a property fetch (`case A = G::A->value + 1;`)
        // must still be detected: Zend 8.4.13 fails with "Cannot declare
        // self-referencing constant G::A".
        $this->exec('Cannot declare self-referencing constant `G::A`', 'enum_case_property_self_reference.php');
    }

    public function testCaseExprResolvesInDeclaringNamespace(): void
    {
        // Verified against Zend 8.4.13: A\E::X->value is 21 (A\Helper::V + 1,
        // never the decoy B\Helper::V of 999), and B\Holder::REF is the case
        // OBJECT enum(A\E::X) — registered as a persistent enum-case AST, not
        // a folded scalar.
        [$stub] = $this->convertFiles(['enum_case_cross_namespace.php']);
        self::assertStringContainsString('ZVAL_LONG(&enum_case_X_value, 21)', $stub);
        self::assertStringContainsString('const_REF_value_enum_name = zend_string_init_interned("A\\\\E"', $stub);
        self::assertStringContainsString('const_REF_value_case_name = zend_string_init_interned("X"', $stub);
        self::assertStringNotContainsString('1000', $stub);
    }

    public function testCaseExprResolvesAcrossFiles(): void
    {
        // The referencing file converts first, so the lazy evaluation of
        // A\E::X runs while namespace Consumer is active; Provider inside the
        // case expression must still resolve through the declaring file's
        // `use Lib\Provider`. Verified against Zend 8.4.13: the backing value
        // is 42, and Consumer\Holder::REF is the case object enum(A\E::X) —
        // registered as a persistent enum-case AST.
        [$ref, $def] = $this->convertFiles([
            'enum_case_cross_file_ref.php',
            'enum_case_cross_file_def.php',
        ]);
        self::assertStringContainsString('const_REF_value_enum_name = zend_string_init_interned("A\\\\E"', $ref);
        self::assertStringContainsString('const_REF_value_case_name = zend_string_init_interned("X"', $ref);
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
