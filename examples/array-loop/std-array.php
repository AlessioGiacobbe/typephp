<?php

use native_types;

function main()
{
    $array = std::array(std::array(std::array(native_types::type_int, 13), 16), 19);
    $index = 9;
    $index2 = 5;
    $array[$index2][$index][0] = 2026;

    var_dump($array[$index2][$index][0]);
    var_dump($array[$index2][$index]);
}