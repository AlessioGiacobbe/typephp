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
    var_dump(np::$module);
    var_dump(np::version->full_version);
}


