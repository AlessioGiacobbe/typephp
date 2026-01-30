--TEST--
global vars
--FILE--
<?php
global $a;
$a = 100;

var_dump($GLOBALS['a']);
var_dump($a);
var_dump(gettype($_SERVER));
var_dump($_SERVER['argc']);
?>
--EXPECTF--
int(100)
int(100)
string(5) "array"
int(%d)
