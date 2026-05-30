--TEST--
pow int overflow
--FILE--
<?php
use native_types;
function main()
{
    $a = 2 ** 80;
    echo $a, PHP_EOL;
}
?>
--EXPECT--
0