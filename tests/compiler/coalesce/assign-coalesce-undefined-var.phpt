--TEST--
assign coalesce on undefined variable
--FILE--
<?php
$a ??= 123;
var_dump($a);

$b ??= 'foo';
$b ??= 'bar';
var_dump($b);
?>
--EXPECT--
int(123)
string(3) "foo"
