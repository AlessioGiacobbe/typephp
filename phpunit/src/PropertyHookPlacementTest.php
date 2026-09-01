<?php

/**
 * Zend property-hook placement rules for class/trait properties: no
 * hooks on static or readonly properties, abstract hooked properties
 * only in abstract containers with at least one bodiless hook, and a
 * mandatory body on every non-abstract hook.
 */
class PropertyHookPlacementTest extends BaseTest
{
    public function testHooksOnStaticPropertyAreRejected(): void
    {
        $this->exec('Cannot declare hooks for static property', 'hook_rule_static.php');
    }

    public function testHooksOnReadonlyPropertyAreRejected(): void
    {
        $this->exec('Hooked properties cannot be readonly', 'hook_rule_readonly.php');
    }

    public function testHooksInReadonlyClassAreRejected(): void
    {
        $this->exec('Hooked properties cannot be readonly', 'hook_rule_readonly_class.php');
    }

    public function testAbstractHookedPropertyRequiresAbstractClass(): void
    {
        $this->exec('Non-abstract class `Box` contains abstract hooked property `$x`', 'hook_rule_abstract_nonabstract_class.php');
    }

    public function testAbstractPropertyNeedsAtLeastOneAbstractHook(): void
    {
        $this->exec('Abstract property `Box::$x` must specify at least one abstract hook', 'hook_rule_abstract_all_bodies.php');
    }

    public function testOnlyHookedPropertiesMayBeAbstract(): void
    {
        $this->exec('Only hooked properties may be declared abstract', 'hook_rule_abstract_no_hooks.php');
    }

    public function testNonAbstractHookMustHaveBody(): void
    {
        $this->exec('Non-abstract property hook must have a body', 'hook_rule_bodyless.php');
    }

    public function testWellFormedHooksStillCompile(): void
    {
        $this->compile('hook_rule_valid.php');
    }
}
