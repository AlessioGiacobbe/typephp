--TEST--
Native class: strict identity never coerces raw pointers to PHP values
--FILE--
<?php

#[Native]
class NativeStrictIdentity {}

function nativeIdentityOperand(NativeStrictIdentity $value): NativeStrictIdentity
{
    echo "native\n";
    return $value;
}

function zendIdentityOperand(): bool
{
    echo "zend\n";
    return true;
}

function main(): void
{
    $value = new NativeStrictIdentity();
    $alias = $value;
    $other = new NativeStrictIdentity();

    var_dump($value === $alias);
    var_dump($value === $other);
    var_dump($value === true);
    var_dump($value !== true);
    var_dump(nativeIdentityOperand($value) === zendIdentityOperand());
    var_dump(zendIdentityOperand() === nativeIdentityOperand($value));
}

?>
--EXPECT--
bool(true)
bool(false)
bool(false)
bool(true)
native
zend
bool(false)
zend
native
bool(false)
