<?php
// python: import sys
use sys;
// python: import sos
use os;

function main()
{
    var_dump(sys::api_version);
    var_dump(os::getpid());
    var_dump(os::environ->get("PATH"));
}



use numpy as np;

function np()
{
    print(np::$module);
    print(np::version->full_version);
}


$array["hello"] = $a;
$array["world"] = $b;

$c = $array["world"];


