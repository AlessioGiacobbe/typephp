--TEST--
empty (linked expr)
--FILE--
<?php
$arr = array(
    array(2, 2)
);
var_dump(empty($arr[0][1]));
var_dump(empty($arr[0][1][2][3]->prop[4]));
?>
--EXPECT--
bool(false)
bool(true)
