<?php
function fn1()
{
    echo "fn1\n";
    fn2();
}

function fn2()
{
    echo __FUNCTION__ . "\n";
}

fn1();

include __DIR__ . '/expr1.php';
expr1();