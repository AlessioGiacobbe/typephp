<?php

/**
 * Compile-time enum declaration rules mirrored from Zend 8.4:
 * no properties, restricted magic methods, case/value pairing per
 * backing type, case name collisions, backing-type whitelist, and the
 * implicit UnitEnum/BackedEnum/final markers.
 */
class EnumDeclarationRulesTest extends BaseTest
{
    public function testEnumCannotIncludeProperties(): void
    {
        $this->exec('Enum `Suit` cannot include properties', 'enum_rule_property.php');
    }

    public function testEnumCannotIncludeStaticProperties(): void
    {
        $this->exec('Enum `Suit` cannot include properties', 'enum_rule_static_property.php');
    }

    public function testEnumCannotIncludeConstructor(): void
    {
        $this->exec('Enum `Suit` cannot include magic method `__construct`', 'enum_rule_magic_construct.php');
    }

    public function testEnumCannotIncludeToString(): void
    {
        $this->exec('Enum `Suit` cannot include magic method `__toString`', 'enum_rule_magic_tostring.php');
    }

    public function testNonBackedCaseMustNotHaveValue(): void
    {
        $this->exec('Case `Hearts` of non-backed enum `Suit` must not have a value', 'enum_rule_case_value_nonbacked.php');
    }

    public function testBackedCaseMustHaveValue(): void
    {
        $this->exec('Case `Hearts` of backed enum `Suit` must have a value', 'enum_rule_case_missing_value.php');
    }

    public function testDuplicateCaseIsRejected(): void
    {
        $this->exec('Cannot redefine class constant `Suit::Hearts`', 'enum_rule_duplicate_case.php');
    }

    public function testCaseClashingWithConstantIsRejected(): void
    {
        $this->exec('Cannot redefine class constant `Suit::Hearts`', 'enum_rule_case_const_clash.php');
    }

    public function testBackingTypeMustBeIntOrString(): void
    {
        $this->exec('Enum backing type must be `int` or `string`, `float` given', 'enum_rule_backing_type.php');
    }

    public function testExplicitUnitEnumImplementsIsRejected(): void
    {
        $this->exec('Enum `Suit` cannot implement previously implemented interface `UnitEnum`', 'enum_rule_implements_unitenum.php');
    }

    public function testNonBackedEnumCannotImplementBackedEnum(): void
    {
        $this->exec('Non-backed enum `Suit` cannot implement interface `BackedEnum`', 'enum_rule_implements_backedenum_nonbacked.php');
    }

    public function testEnumCannotIncludeAbstractMethod(): void
    {
        $this->exec('Enum `Suit` cannot include abstract method `f()`', 'enum_rule_abstract_method.php');
    }

    public function testClassCannotExtendEnum(): void
    {
        // Enum ClassDef flags carry Modifiers::FINAL, so the regular
        // final-class inheritance check rejects the extension.
        $this->exec('Class `Deck` cannot extend final class `Suit`', 'enum_rule_extends_enum.php');
    }

    public function testWellFormedEnumStillCompiles(): void
    {
        $this->compile('enum_rule_valid.php');
    }

    public function testTraitInjectedMagicMethodIsRejected(): void
    {
        // The forbidden-magic-method check must also cover methods composed
        // into the enum from a trait, not only ones declared in its body.
        $this->exec('Enum `Suit` cannot include magic method `__construct`', 'enum_rule_trait_magic_construct.php');
    }

    public function testTraitAliasToMagicNameIsRejected(): void
    {
        // A trait alias that renames an ordinary method to a forbidden magic
        // name installs that magic method into the enum; Zend rejects it.
        $this->exec('Enum `Suit` cannot include magic method `__destruct`', 'enum_rule_trait_alias_magic.php');
    }
}
