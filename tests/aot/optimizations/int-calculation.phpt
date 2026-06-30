--TEST--
SSA: int
--FILE--
<?php
function main(): void {
    $a = 100;
    $a += 3;
    $a *= 232;
    $a %= 3412;
    echo $a, PHP_EOL;

    $b = $a + PHP_INT_MAX;
    echo $b, PHP_EOL;
}
?>
--EXPECTF--
12
-9223372036854775797