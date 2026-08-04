--TEST--
Constant integer arithmetic overflow promotes to float like PHP
--FILE--
<?php
declare(strict_types=1);

function main(): void
{
    var_dump(PHP_INT_MAX + 1);
    var_dump(PHP_INT_MIN - 1);
    var_dump(PHP_INT_MAX * 2);
    var_dump(PHP_INT_MIN / -1);
    var_dump(1 + 2);
    var_dump(PHP_INT_MAX + 0);
    var_dump(-PHP_INT_MIN);
}
?>
--EXPECTF--
float(9.223372036854776E+18)
float(-9.223372036854776E+18)
float(1.8446744073709552E+19)
float(9.223372036854776E+18)
int(3)
int(9223372036854775807)
float(9.223372036854776E+18)
