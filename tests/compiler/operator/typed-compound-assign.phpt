--TEST--
Compound assignment and ++/-- follow PHP semantics on typed and untyped slots
--FILE--
<?php
declare(strict_types=1);

function modZero(int $a): int
{
    try {
        $a %= 0;
    } catch (DivisionByZeroError $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
    return $a;
}

function shiftNeg(int $a): int
{
    try {
        $a <<= -1;
    } catch (ArithmeticError $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
    return $a;
}

function divEven(int $a): string
{
    $a /= 2;
    return (string) $a;
}

function postIncPair(int $a): string
{
    $b = $a++;
    return $b . ',' . $a;
}

function loopSum(int $n): int
{
    $sum = 0;
    for ($i = 0; $i < $n; $i++) {
        $sum += $i;
    }
    return $sum;
}

function main(): void
{
    var_dump(modZero(5));
    var_dump(shiftNeg(4));
    echo divEven(8), "\n";
    echo postIncPair(5), "\n";
    var_dump(loopSum(5));

    $x = 7;
    $x /= 2;
    var_dump($x);

    $y = PHP_INT_MAX;
    $y += 1;
    var_dump($y);

    $z = 5;
    try {
        $z %= 0;
    } catch (DivisionByZeroError $e) {
        echo "caught: " . $e->getMessage() . "\n";
    }
    var_dump($z);
}
?>
--EXPECT--
caught: Modulo by zero
int(5)
caught: Bit shift by negative number
int(4)
4
5,6
int(10)
float(3.5)
float(9.223372036854776E+18)
caught: Modulo by zero
int(5)
