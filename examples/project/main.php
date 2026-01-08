<?php
function main()
{
    fn1();
    $rs = fn_test(199, 189);
    $array = [1, 3, 5];
    foreach ($array as $r) {
        echo $r;
    }
    var_dump(gettype($rs));
}