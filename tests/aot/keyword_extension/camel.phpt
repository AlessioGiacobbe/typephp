--TEST--
Keyword extension methods support lowerCamelCase function names
--FILE--
<?php

declare(strict_types=1);
use native_types;

function __inspectValue(mixed $value, string $prefix): void
{
    echo $prefix, ':', $value, "\n";
}

function main(): void
{
    $value = 42;
    $value->inspectValue('number');
}
?>
--EXPECT--
number:42
