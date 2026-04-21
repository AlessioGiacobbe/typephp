<?php
function bar()
{
    return 'string';
}
function foo(stdClass $o)
{
    $o = bar();
}

function main()
{
    $obj = new stdClass();
    foo($obj);
}