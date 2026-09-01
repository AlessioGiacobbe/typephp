<?php

/**
 * Zend readonly property declaration rules for ZendVM-backed classes:
 * no defaults, mandatory type, no static readonly, and the readonly
 * class modifier applying the same rules to every property.
 */
class ReadonlyDeclarationRulesTest extends BaseTest
{
    public function testReadonlyPropertyCannotHaveDefault(): void
    {
        $this->exec('Readonly property `Cfg::$port` cannot have default value', 'readonly_rule_default.php');
    }

    public function testReadonlyPropertyMustHaveType(): void
    {
        $this->exec('Readonly property `Cfg::$port` must have type', 'readonly_rule_untyped.php');
    }

    public function testStaticPropertyCannotBeReadonly(): void
    {
        $this->exec('Static property `Cfg::$port` cannot be readonly', 'readonly_rule_static.php');
    }

    public function testPromotedReadonlyParamMustHaveType(): void
    {
        $this->exec('Readonly property `Cfg::$port` must have type', 'readonly_rule_promoted_untyped.php');
    }

    public function testReadonlyClassPropertyMustHaveType(): void
    {
        $this->exec('Readonly property `Cfg::$port` must have type', 'readonly_rule_class_untyped.php');
    }

    public function testReadonlyClassCannotDeclareStaticProperty(): void
    {
        $this->exec('Static property `Cfg::$port` cannot be readonly', 'readonly_rule_class_static.php');
    }

    public function testWellFormedReadonlyDeclarationsStillCompile(): void
    {
        // Promoted readonly params may keep a parameter default: it belongs
        // to the constructor argument, not to the property.
        $this->compile('readonly_rule_valid.php');
    }
}
