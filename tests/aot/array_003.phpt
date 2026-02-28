--TEST--
Array 003
--FILE--
<?php
$key = 100;

$array2 = [
    $key => 'foo',
    1000 => 'bar',
];
var_dump(count($array2));
?>
--EXPECT--
int(2)