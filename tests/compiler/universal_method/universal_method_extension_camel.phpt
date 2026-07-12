--TEST--
Universal extension methods support lowerCamelCase function names
--FILE--
<?php

use native_types;

function int_toBytes(int $value): string
{
    return ($value / 1024) . 'Kb';
}

function array_getFirstElement(array $value): mixed
{
    return $value[0];
}

function main(): void
{
    $size = 2048;
    $values = [42, 43];
    var_dump($size->toBytes());
    var_dump($values->getFirstElement());
}
?>
--EXPECT--
string(3) "2Kb"
int(42)
