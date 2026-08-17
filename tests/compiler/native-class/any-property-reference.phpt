--TEST--
Native any properties support PHP references without runtime type dispatch
--FILE--
<?php

#[Native]
class NativeAnyReference
{
    // `mixed` is the PHP spelling of TypePHP's equivalent `any` storage.
    public mixed $value = 1;
    public ?NativeAnyReference $child;
}

function replaceAny(mixed &$value, mixed $replacement): void
{
    $value = $replacement;
}

function main(): void
{
    $object = new NativeAnyReference();
    $reference =& $object->value;
    $reference = 'changed';
    var_dump($object->value);

    $object->value = 42;
    var_dump($reference);

    $object->child = new NativeAnyReference();
    $childReference =& $object->child->value;
    replaceAny($childReference, ['native', 'reference']);
    var_dump($object->child->value);
}

?>
--EXPECT--
string(7) "changed"
int(42)
array(2) {
  [0]=>
  string(6) "native"
  [1]=>
  string(9) "reference"
}
