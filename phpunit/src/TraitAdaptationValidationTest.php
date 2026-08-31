<?php

use TypePhp\Exception\TestError;

/**
 * Zend validates trait adaptations while binding traits: an alias must name a
 * method that exists in a used trait, and a precedence rule must name used
 * traits and an existing preferred method (the overridden trait need not
 * declare it). Methods arriving from nested traits satisfy adaptations on the
 * directly-used trait.
 */
class TraitAdaptationValidationTest extends BaseTest
{
    public function testValidAdaptationsCompile(): void
    {
        $this->compile('trait_adaptations_valid.php');
    }

    public function testUnqualifiedAliasForMissingMethod(): void
    {
        $this->exec(
            'An alias (`g`) was defined for method `missing()`, but this method does not exist',
            'trait_alias_missing_method.php',
        );
    }

    public function testQualifiedAliasForMissingMethod(): void
    {
        $this->exec(
            'An alias was defined for `A::missing` but this method does not exist',
            'trait_alias_missing_qualified.php',
        );
    }

    public function testAliasReferencingUnusedTrait(): void
    {
        $this->exec(
            "Required Trait `B` wasn't added to `C`",
            'trait_alias_trait_not_used.php',
        );
    }

    public function testPrecedenceRuleForMissingMethod(): void
    {
        $this->exec(
            'A precedence rule was defined for `B::f` but this method does not exist',
            'trait_insteadof_missing_method.php',
        );
    }

    public function testPrecedenceRuleReferencingUnusedTrait(): void
    {
        $this->exec(
            "Required Trait `D` wasn't added to `C`",
            'trait_insteadof_trait_not_used.php',
        );
    }
}
