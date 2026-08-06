--TEST--
Constant integer arithmetic overflow promotes to float like PHP
--FILE--
<?php
declare(strict_types=1);

function overflowInferred()
{
    return PHP_INT_MAX + 1;
}

function unaryOverflowInferred()
{
    return -PHP_INT_MIN;
}

function fractionalDivisionInferred()
{
    return 1 / 2;
}

function main(): void
{
    var_dump(PHP_INT_MAX + 1);
    var_dump(PHP_INT_MIN - 1);
    var_dump(PHP_INT_MAX * 2);
    var_dump(PHP_INT_MIN / -1);
    var_dump(1 + 2);
    var_dump(PHP_INT_MAX + 0);
    var_dump(-PHP_INT_MIN);
    $overflow = PHP_INT_MAX + 1;
    var_dump($overflow, is_int($overflow), is_float($overflow));
    var_dump(overflowInferred(), is_float(overflowInferred()));
    var_dump(unaryOverflowInferred(), is_float(unaryOverflowInferred()));
    var_dump(fractionalDivisionInferred(), is_float(fractionalDivisionInferred()));
    var_dump((PHP_INT_MAX + 1) === (float) PHP_INT_MAX);
    var_dump(PHP_INT_MIN % -1);
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
float(9.223372036854776E+18)
bool(false)
bool(true)
float(9.223372036854776E+18)
bool(true)
float(9.223372036854776E+18)
bool(true)
float(0.5)
bool(true)
bool(true)
int(0)
