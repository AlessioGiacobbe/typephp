--TEST--
Call arguments and concat operands follow Zend's operand read order around side effects
--FILE--
<?php
declare(strict_types=1);

function pair(int $a, int $b): string
{
    return $a . ',' . $b;
}

function main(): void
{
    // Call arguments are sent strictly left to right.
    $j = 1;
    var_dump(pair($j, $j = 5));
    var_dump($j);

    $n = 2;
    var_dump(pair($n, $n *= 3));
    var_dump($n);

    // Concat chains: a variable is read when its concat op executes, so it
    // sees the side effects of everything up to and including the operand it
    // is combined with, but nothing later.
    $m = 1;
    var_dump($m . ',' . ($m = 9));
    var_dump($m);

    // The first two operands are read together at the first op, after the
    // second operand's assignment.
    $s = 'a';
    var_dump($s . ($s = 'b') . $s);

    $a = 'a';
    var_dump($a . ($a = 'x') . $a . ($a = 'y'));

    $t = 'p';
    $u = 'a';
    $t .= $u . ($u = 'b');
    var_dump($t);

    $t2 = 'p';
    $u2 = 'a';
    $t2 .= $u2 . ',' . ($u2 = 'b');
    var_dump($t2);

    $w = 'a';
    var_dump('pre' . $w . ($w = 'z'));

    // Zend reads the CV when the ADD executes, after the nested assignment.
    $k = 1;
    var_dump($k + ($k = 5));
}
?>
--EXPECT--
string(3) "1,5"
int(5)
string(3) "2,6"
int(6)
string(3) "1,9"
int(9)
string(3) "bbb"
string(4) "xxxy"
string(3) "pbb"
string(4) "pa,b"
string(5) "preaz"
int(10)
