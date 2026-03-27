--TEST--
object link operator
--FILE--
<?php
function main()
{
    $a = var_dump('foo');
    var_dump($a);
}
?>
--EXPECT--
string(3) "foo"
NULL