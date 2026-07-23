--TEST--
array reference assignment to element: $arr[$k] = &$v writes back through reference
--FILE--
<?php
function main()
{
    $x = 10;
    $y = 20;
    $arr = [1, 2, 3];
    $arr[0] = &$x;   // 覆盖已有元素为引用
    $arr[5] = &$y;   // 新建元素为引用
    $x = 100;
    $y = 200;
    var_dump($arr[0], $arr[5]); // 100, 200

    // 通过元素引用写回
    $arr[0] = 111;
    $arr[5] = 222;
    var_dump($x, $y); // 111, 222

    // 嵌套：引用赋值到多维数组元素
    $z = 7;
    $m = [[1], [2]];
    $m[0][0] = &$z;
    $z = 77;
    var_dump($m[0][0]); // 77
}
?>
--EXPECT--
int(100)
int(200)
int(111)
int(222)
int(77)
