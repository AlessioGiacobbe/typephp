--TEST--
GH-18897 (printf: empty precision is interpreted as precision 6, not as precision 0)
--SKIPIF--
<?php
echo 'skip AOT limitation';
?>
--FILE--
<?php
printf("%.f\n", 3.1415926535);
printf("%.0f\n", 3.1415926535);
?>
--EXPECT--
3
3
