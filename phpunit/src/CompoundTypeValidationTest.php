<?php

/**
 * Compound type well-formedness, shared across parameters, returns,
 * properties, and constants: duplicate members, bool/true/false
 * overlap, standalone-only types, nullable restrictions, intersection
 * member rules, whole-DNF redundancy, `object` absorbing class types,
 * class-scope type keywords, and duplicate implements.
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

    public function testSelfCannotJoinBareIntersection(): void
    {
        $this->exec('Type `self` cannot be part of an intersection type', 'type_rule_self_intersect_method.php');
    }

    public function testSelfCannotJoinDnfIntersection(): void
    {
        $this->exec('Type `self` cannot be part of an intersection type', 'type_rule_self_dnf_method.php');
    }

    public function testParentCannotJoinDnfIntersection(): void
    {
        $this->exec('Type `parent` cannot be part of an intersection type', 'type_rule_parent_dnf_method.php');
    }

    public function testStaticCannotJoinBareIntersectionReturn(): void
    {
        $this->exec('Type `static` cannot be part of an intersection type', 'type_rule_static_intersect_return.php');
    }

    public function testStaticCannotJoinDnfIntersectionReturn(): void
    {
        $this->exec('Type `static` cannot be part of an intersection type', 'type_rule_static_dnf_return.php');
    }

    public function testSelfCannotJoinDnfIntersectionInProperty(): void
    {
        $this->exec('Type `self` cannot be part of an intersection type', 'type_rule_self_dnf_property.php');
    }

    public function testParentCannotJoinDnfIntersectionInConstant(): void
    {
        $this->exec('Type `parent` cannot be part of an intersection type', 'type_rule_parent_dnf_const.php');
    }

    public function testSelfCannotJoinDnfIntersectionInPromotedParam(): void
    {
        $this->exec('Type `self` cannot be part of an intersection type', 'type_rule_self_dnf_promoted.php');
    }

    public function testClassScopeKeywordBesideDnfGroupStillCompiles(): void
    {
        $this->compile('type_rule_keyword_beside_dnf_valid.php');
    }

    public function testObjectWithClassTypeIsRedundant(): void
    {
        $this->exec(
            'Type `Foo|object` contains both object and a class type, which is redundant',
            'type_rule_object_class_union.php',
        );
    }

    public function testObjectWithClassTypeIsRedundantInEitherOrder(): void
    {
        $this->exec(
            'Type `Foo|object` contains both object and a class type, which is redundant',
            'type_rule_object_class_union_reversed.php',
        );
    }

    public function testObjectWithInterfaceTypeIsRedundant(): void
    {
        $this->exec(
            'Type `Ifc|object` contains both object and a class type, which is redundant',
            'type_rule_object_interface_union.php',
        );
    }

    public function testObjectWithDnfGroupIsRedundant(): void
    {
        $this->exec(
            'Type `(A&B)|object` contains both object and a class type, which is redundant',
            'type_rule_object_dnf_union.php',
        );
    }

    public function testPermutedDnfGroupIsRedundant(): void
    {
        $this->exec('Type `B&A` is redundant with type `A&B`', 'type_rule_dnf_permuted.php');
    }

    public function testDnfGroupMoreRestrictiveThanPlainMemberIsRedundant(): void
    {
        $this->exec(
            'Type `A&B` is redundant as it is more restrictive than type `A`',
            'type_rule_dnf_subset.php',
        );
    }

    public function testDnfGroupMoreRestrictiveThanPlainMemberIsRedundantInEitherOrder(): void
    {
        $this->exec(
            'Type `A&B` is redundant as it is more restrictive than type `A`',
            'type_rule_dnf_subset_reversed.php',
        );
    }

    public function testDnfSupersetGroupIsRedundant(): void
    {
        $this->exec(
            'Type `A&B&C2` is redundant as it is more restrictive than type `A&B`',
            'type_rule_dnf_superset_group.php',
        );
    }

    public function testStaticReturnRequiresClassScope(): void
    {
        $this->exec('Cannot use "static" when no class scope is active', 'type_rule_static_return_global.php');
    }

    public function testStaticUnionReturnRequiresClassScope(): void
    {
        $this->exec('Cannot use "static" when no class scope is active', 'type_rule_static_union_return_global.php');
    }

    public function testSelfReturnRequiresClassScope(): void
    {
        $this->exec('Cannot use "self" when no class scope is active', 'type_rule_self_return_global.php');
    }

    public function testSelfParameterRequiresClassScope(): void
    {
        $this->exec('Cannot use "self" when no class scope is active', 'type_rule_self_param_global.php');
    }

    public function testSelfUnionReturnRequiresClassScope(): void
    {
        $this->exec('Cannot use "self" when no class scope is active', 'type_rule_self_union_return_global.php');
    }

    public function testSelfNullableParameterRequiresClassScope(): void
    {
        $this->exec('Cannot use "self" when no class scope is active', 'type_rule_self_nullable_param_global.php');
    }

    public function testSelfDnfParameterRequiresClassScope(): void
    {
        $this->exec('Cannot use "self" when no class scope is active', 'type_rule_self_dnf_param_global.php');
    }

    public function testParentParameterRequiresClassScope(): void
    {
        $this->exec('Cannot use "parent" when no class scope is active', 'type_rule_parent_param_global.php');
    }

    public function testParentParameterRequiresParentClass(): void
    {
        $this->exec('Cannot use "parent" when current class scope has no parent', 'type_rule_parent_no_parent_method.php');
    }

    public function testParentPropertyRequiresParentClass(): void
    {
        $this->exec('Cannot use "parent" when current class scope has no parent', 'type_rule_parent_no_parent_property.php');
    }

    public function testDuplicateImplementsIsRejected(): void
    {
        $this->exec('Class `C` cannot implement previously implemented interface `Ia`', 'type_rule_implements_dup.php');
    }

    public function testWellFormedCompoundTypesStillCompile(): void
    {
        $this->compile('type_rule_valid.php');
    }

    public function testClassScopeKeywordsInsideClassLikeScopesStillCompile(): void
    {
        $this->compile('type_rule_scope_valid.php');
    }
}
