<?php

/**
 * Regression test for the trait `parent::` / `trait_parent_ce` declaration bug.
 *
 * A trait method whose body contains `parent::` calls is compiled with an implicit
 * `zend_class_entry *trait_parent_ce` parameter (so `parent::` can be bound to the
 * class that composes the trait). The shared `func_decl.h` declaration must emit the
 * same parameter; otherwise the generated C++ fails to compile with C2660
 * ("function does not accept 3 arguments") at the call site that forwards to the trait
 * function. This is a code-generation-level check that fails before the fix and passes
 * after it.
 */
class TraitFuncDeclTest extends \BaseTest
{
    public function testAliasedTraitConstructorParentCallDeclaresTraitParentCe(): void
    {
        // BaseTest::compile() populates the global $translator and translates the file.
        $this->compile('trait-aliased-constructor-parent-call.php');

        global $translator;
        $compiler = $translator;

        $headerPath = $compiler->getIncludeDir() . '/php_trait_parent_ce_func_decl.h';
        if (file_exists($headerPath)) {
            @unlink($headerPath);
        }
        // Emit the shared function-declaration header (genFunctionDeclarations), which
        // is what the fix targets.
        $compiler->genFunctionDeclarations($headerPath);

        $decl = file_get_contents($headerPath);
        $this->assertStringContainsString(
            'trait_parent_ce',
            $decl,
            'func_decl.h must declare the implicit trait_parent_ce parameter for trait methods with parent:: calls'
        );
    }
}
