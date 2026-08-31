<?php

/**
 * Trait composition drops an abstract requirement once a concrete method is
 * available for the same name. These tests cover the validation that must
 * happen before the requirement is dropped: the implementation — whether the
 * class's own method or a concrete method from another trait — has to satisfy
 * the abstract declaration under PHP's method variance rules.
 */
class TraitAbstractRequirementTest extends BaseTest
{
    public function testClassMethodMustSatisfyAbstractTraitRequirement(): void
    {
        $this->exec(
            'Declaration of `InvalidImplementation::value()` must be compatible with `RequiresValue::value()`',
            'trait_abstract_class_incompatible.php'
        );
    }

    public function testLaterConcreteTraitMethodMustSatisfyEarlierAbstract(): void
    {
        $this->exec(
            'Declaration of `HasName::name()` must be compatible with `NeedsName::name()`',
            'trait_abstract_trait_concrete_incompatible.php'
        );
    }

    public function testEarlierConcreteTraitMethodMustSatisfyLaterAbstract(): void
    {
        $this->exec(
            'Declaration of `HasName::name()` must be compatible with `NeedsName::name()`',
            'trait_abstract_trait_concrete_first_incompatible.php'
        );
    }

    public function testStaticnessMustMatchAbstractTraitRequirement(): void
    {
        $this->exec(
            'Cannot make non static method `RequiresValue::value()` static in class `StaticImplementation`',
            'trait_abstract_static_mismatch.php'
        );
    }

    public function testImplementationCannotRequireMoreParameters(): void
    {
        $this->exec(
            'Declaration of `GreedyImplementation::value()` must be compatible with `RequiresValue::value()`',
            'trait_abstract_extra_required_param.php'
        );
    }

    public function testReturnTypeCannotBeWidened(): void
    {
        $this->exec(
            'Declaration of `WideningImplementation::value()` must be compatible with `RequiresValue::value()`',
            'trait_abstract_return_widened.php'
        );
    }

    public function testAliasedAbstractRequirementIsValidatedAgainstClassMethod(): void
    {
        $this->exec(
            'Declaration of `AliasImplementation::renamed()` must be compatible with `RequiresValue::value()`',
            'trait_abstract_alias_incompatible.php'
        );
    }

    public function testValidVarianceIsAccepted(): void
    {
        // Contravariant parameters, covariant returns, extra optional
        // parameters, and visibility changes are all valid ways to fulfill
        // an abstract trait requirement.
        $this->compile('trait_abstract_variance_ok.php');
    }
}
