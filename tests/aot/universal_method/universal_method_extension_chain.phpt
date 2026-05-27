--TEST--
Universal method: extension function chaining with typed return
--FILE--
<?php

use native_types;

function int_to_words(int $int): string
{
    $map = [1 => 'one', 2 => 'two', 3 => 'three'];
    return $map[$int] ?? 'unknown';
}

function str_double(string $str): string
{
    return $str . $str;
}

function str_get_length(string $str): int
{
    return strlen($str);
}

function str_to_array(string $str, string $delimiter): array
{
    return $str->split($delimiter);
}

function array_last(array $arr): mixed {
    if ($arr->count() === 0) {
        return null;
    }
    return $arr[$arr->count() - 1];
}

function main()
{
    $num = 2;
    var_dump($num->toWords()->upper());
    var_dump($num->toWords()->double()->upper());

    $str = "hello";
    var_dump($str->getLength()->add(100));
    var_dump($str->double()->length());

    $str2 = "hello world";
    var_dump($str2->split(" ")->last());
}
?>
--EXPECT--
string(3) "TWO"
string(6) "TWOTWO"
int(105)
int(10)
string(5) "world"