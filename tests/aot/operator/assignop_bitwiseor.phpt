--TEST--
Operator: AssignOp BitwiseOr
--FILE--
<?php
function main()
{
    $a =  100;
    $a |= 16;
    var_dump($a);
}
?>
--EXPECT--
int(116)
