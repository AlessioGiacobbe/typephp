--TEST--
keyword extension method: varDump()
--FILE--
<?php
declare(strict_types=1);
use native_types;

function __var_dump(mixed $var): void {
    var_dump($var);
}

function main(): void {
    $str = "hello world";
    $str->varDump();

    $int_val = 42;
    $int_val->varDump();

    $float_val = 3.14;
    $float_val->varDump();
}
?>
--EXPECT--
string(11) "hello world"
int(42)
float(3.14)
