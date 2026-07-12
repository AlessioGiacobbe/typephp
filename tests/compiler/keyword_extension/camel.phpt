--TEST--
Keyword ExtensionProvider method with lowerCamelCase name
--FILE--
<?php

declare(strict_types=1);
use native_types;

#[ExtensionProvider(complex_types::type_any)]
final class KeywordExtensions
{
    public static function inspectValue(mixed $value, string $prefix): void
    {
        echo $prefix, ':', $value, "\n";
    }
}

function main(): void
{
    $value = 42;
    $value->inspectValue('number');
}
?>
--EXPECT--
number:42
