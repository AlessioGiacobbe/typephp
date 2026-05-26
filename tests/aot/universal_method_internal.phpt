--TEST--
Universal method: PHP internal function as extension method via reflection
--FILE--
<?php

use native_types;

function main()
{
    $str = "hello";
    var_dump($str->rot13());
    var_dump($str->rot13()->upper());
    var_dump($str->rot13()->length());
}
?>
--EXPECT--
string(5) "uryyb"
string(5) "URYYB"
int(5)
