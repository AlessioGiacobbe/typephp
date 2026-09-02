<?php

use TypePhp\Exception\TestError;

/**
 * Zend compares trait data members by VALUE when flattening traits into a
 * class: `1 + 1` and `2`, or `[1, 2]` and `array(1, 2)`, are the same
 * definition, while genuinely different values (and identical-but-differently
 * typed ones, e.g. 1 vs 1.0 on an untyped constant) conflict.
 */
class TraitMemberValueConflictTest extends BaseTest
{
    public function testSameValueDifferentSpellingCompiles(): void
    {
        $this->compile('trait_member_same_value_spelling.php');
    }

    public function testDifferentConstantValuesConflict(): void
    {
        $this->exec('constant `x` already exists', 'trait_const_value_conflict.php');
    }

    public function testDifferentPropertyDefaultsConflict(): void
    {
        $this->exec('property `p` already exists', 'trait_prop_value_conflict.php');
    }

    public function testValueComparisonIsIdentityNotEquality(): void
    {
        $this->exec('constant `x` already exists', 'trait_const_identity_conflict.php');
    }

    public function testSameEnumCaseInBothTraitsCompiles(): void
    {
        $this->compile('trait_const_enum_case_same.php');
    }

    public function testSelfConstantReferenceEvaluatesInNamespace(): void
    {
        $this->compile('trait_const_self_reference_namespaced.php');
    }

    public function testSameNamedCasesOfDifferentEnumsConflict(): void
    {
        $this->exec('constant `x` already exists', 'trait_const_enum_case_conflict.php');
    }

    public function testDifferentCasesOfSameEnumConflict(): void
    {
        $this->exec('constant `x` already exists', 'trait_const_enum_diff_case_conflict.php');
    }

    public function testSameBackingValueOfDifferentEnumsConflicts(): void
    {
        $this->exec('constant `x` already exists', 'trait_const_enum_backed_conflict.php');
    }
}
