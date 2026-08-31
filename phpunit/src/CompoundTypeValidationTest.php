<?php

/**
 * Compound type well-formedness, shared across parameters, returns,
 * properties, and constants: duplicate members, bool/true/false
 * overlap, standalone-only types, nullable restrictions, intersection
 * member rules, class-scope type keywords, and duplicate implements.
 */
class CompoundTypeValidationTest extends BaseTest
{
    public function testDuplicateUnionMemberIsRejected(): void
    {
        $this->exec('Duplicate type `int` is redundant', 'type_rule_dup_union.php');
    }

    public function testDuplicateClassUnionMemberIsRejected(): void
    {
        $this->exec('Duplicate type `Foo` is redundant', 'type_rule_dup_class_union.php');
    }

    public function testBoolWithFalseIsRedundant(): void
    {
        $this->exec('Duplicate type `false` is redundant', 'type_rule_bool_false.php');
    }

    public function testTrueWithFalseMustUseBool(): void
    {
        $this->exec('Type contains both `true` and `false`, `bool` must be used instead', 'type_rule_true_false.php');
    }

    public function testMixedCannotBeUnionMember(): void
    {
        $this->exec('Type `mixed` can only be used as a standalone type', 'type_rule_mixed_union.php');
    }

    public function testMixedCannotBeNullable(): void
    {
        $this->exec('Type `mixed` cannot be marked as nullable since mixed already includes null', 'type_rule_nullable_mixed.php');
    }

    public function testVoidCannotBeUnionMember(): void
    {
        $this->exec('Type `void` can only be used as a standalone type', 'type_rule_void_union.php');
    }

    public function testIterableExpansionDetectsArrayDuplicate(): void
    {
        $this->exec('Duplicate type `array` is redundant', 'type_rule_iterable_array.php');
    }

    public function testScalarCannotJoinIntersection(): void
    {
        $this->exec('Type `int` cannot be part of an intersection type', 'type_rule_intersect_scalar.php');
    }

    public function testDuplicateIntersectionMemberIsRejected(): void
    {
        $this->exec('Duplicate type `Ix` is redundant', 'type_rule_intersect_dup.php');
    }

    public function testStaticReturnRequiresClassScope(): void
    {
        $this->exec('Cannot use "static" when no class scope is active', 'type_rule_static_return_global.php');
    }

    public function testSelfReturnRequiresClassScope(): void
    {
        $this->exec('Cannot use "self" when no class scope is active', 'type_rule_self_return_global.php');
    }

    public function testDuplicateImplementsIsRejected(): void
    {
        $this->exec('Class `C` cannot implement previously implemented interface `Ia`', 'type_rule_implements_dup.php');
    }

    public function testWellFormedCompoundTypesStillCompile(): void
    {
        $this->compile('type_rule_valid.php');
    }
}
