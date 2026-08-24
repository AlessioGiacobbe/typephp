--TEST--
PHP 8.4 property hooks expose Zend reflection metadata
--FILE--
<?php

final class ReflectedPropertyHooks
{
    public string $virtual {
        final get => 'value';
        set {
        }
    }
}

function main(): void
{
    $property = new ReflectionProperty(ReflectedPropertyHooks::class, 'virtual');
    var_dump($property->hasHooks());
    var_dump($property->isVirtual());
    foreach ($property->getHooks() as $kind => $hook) {
        echo $kind, ':', $hook->getName(), ':', $hook->isFinal() ? 'final' : 'not-final', "\n";
    }
}
?>
--EXPECT--
bool(true)
bool(true)
get:$virtual::get:final
set:$virtual::set:not-final
