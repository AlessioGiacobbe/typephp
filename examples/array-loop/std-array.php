<?php

use native_types;

function main()
{
    $array = std::array(std::array(native_types::type_int, 10), 10);
    $index = 9;
    $index2 = 5;
    $array[$index2][$index] = 2026;
//    $array = std::array(native_types::type_int, 100);
//    $array[99] = 2026;
    var_dump($array);
}