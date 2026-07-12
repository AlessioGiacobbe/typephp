--TEST--
any
--FILE--
<?php
ini_set('precision', 17);
$s = 12345678;
var_dump(strlen($s));
var_dump(strrev($s));
var_dump(strlen(3.1415926));
?>
--EXPECT--
int(8)
string(8) "87654321"
int(18)
