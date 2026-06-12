--TEST--
reset/end: TypeError for non-array
--FILE--
<?php
$str = "hello";
try { reset($str); } catch (TypeError $e) { echo $e->getMessage() . "\n"; }
$int = 42;
try { end($int); } catch (TypeError $e) { echo $e->getMessage() . "\n"; }
$arr = ["a" => 1, "b" => 2];
var_dump(reset($arr));
var_dump(end($arr));
?>
--EXPECT--
reset(): Argument #1 ($array) must be of type array
end(): Argument #1 ($array) must be of type array
int(1)
int(2)
