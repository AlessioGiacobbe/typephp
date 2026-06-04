--TEST--
SSA narrowing: reference assignment prevents narrowing
--FILE--
<?php
function main(): void {
    $a = 100;
    $b = &$a;
    $a = 200;
    echo $b, PHP_EOL;

    // $c not referenced → can be narrowed
    $c = 50;
    $c += 25;
    echo $c, PHP_EOL;
}
?>
--EXPECT--
200
75
