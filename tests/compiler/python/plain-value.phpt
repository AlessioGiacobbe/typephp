--TEST--
PyObject toPlainValue() explicitly returns a PHP value
--SKIPIF--
<?php
if (!extension_loaded('phpy')) {
    die('skip phpy extension is not loaded');
}
?>
--FILE--
<?php

function main(): void
{
    $list = python\list([1, 2, 3]);
    $integer = python\int(42);

    var_dump(toPlainValue($list));
    var_dump(toPlainValue($list)->toArray());
    var_dump($integer->toPlainValue());
    var_dump($integer->toPlainValue()->toInt());

    try {
        toPlainValue(new stdClass());
    } catch (Error $error) {
        var_dump(str_contains($error->getMessage(), 'supports PyObject only'));
    }
}

function toPlainValue(mixed $value): mixed
{
    return $value->toPlainValue();
}
?>
--EXPECT--
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
int(42)
int(42)
bool(true)
