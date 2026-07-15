--TEST--
Reference-returning calls are copied by value when used as call arguments or array elements
--FILE--
<?php

function main()
{
    $v1 = &test1();
    var_dump($v1);
    $v2 = &test1();
    var_dump($v2);
    var_dump($v1, $v2);
    $v1 = 0;
    var_dump($v1, $v2);
    var_dump(test1(), test2());
    var_dump([test1(), test2()]);
}

function &test1()
{
    $callback = 'test2';
    return $callback();
}

function &test2()
{
    static $value = 0;
    ++$value;
    return $value;
}
?>
--EXPECT--
int(1)
int(2)
int(2)
int(2)
int(0)
int(0)
int(1)
int(2)
array(2) {
  [0]=>
  int(3)
  [1]=>
  int(4)
}
