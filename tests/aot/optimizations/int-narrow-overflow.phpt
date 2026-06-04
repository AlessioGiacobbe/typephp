--TEST--
SSA narrowing: int overflow prevention (PHP_INT_MAX)
--FILE--
<?php
function main(): void {
    $a = 2;

    // $a + PHP_INT_MAX overflows to float at runtime
    $b = $a + PHP_INT_MAX;
    echo gettype($b), PHP_EOL;

    // $a * PHP_INT_MAX also overflows
    $c = $a * PHP_INT_MAX;
    echo gettype($c), PHP_EOL;

    // Two small ints added safely → narrowed to int
    $d = $a + 99;
    var_dump($d);
}
?>
--EXPECT--
double
double
int(101)
