--TEST--
strlen
--FILE--
<?php
$a = 2;
$a **= 10;
var_dump($a);
?>
--EXPECT--
int(1024)
