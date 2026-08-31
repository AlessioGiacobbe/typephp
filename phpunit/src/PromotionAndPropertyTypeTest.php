<?php

/**
 * Constructor promotion and property/constant type restrictions:
 * no variadic promoted properties, and `callable` is banned from
 * property and class-constant types (bare, nullable, or union member).
 */
class PromotionAndPropertyTypeTest extends BaseTest
{
    public function testVariadicPromotedPropertyIsRejected(): void
    {
        $this->exec('Cannot declare variadic promoted property', 'promotion_rule_variadic.php');
    }

    public function testCallablePropertyTypeIsRejected(): void
    {
        $this->exec('Property `Bag::$fn` cannot have type `callable`', 'property_rule_callable.php');
    }

    public function testCallablePromotedPropertyTypeIsRejected(): void
    {
        $this->exec('Property `Bag::$fn` cannot have type `callable`', 'property_rule_callable_promoted.php');
    }

    public function testCallableUnionPropertyTypeIsRejected(): void
    {
        $this->exec('Property `Bag::$fn` cannot have type `int|callable`', 'property_rule_callable_union.php');
    }

    public function testCallableClassConstantTypeIsRejected(): void
    {
        $this->exec('Class constant `Bag::FN` cannot have type `callable`', 'const_rule_callable.php');
    }
}
