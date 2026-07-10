--TEST--
function returning by reference preserves aliases
--FILE--
<?php
function &value_ref()
{
    global $value;
    return $value;
}
function main()
{
    global $value;
    $value = 1;
    $alias =& value_ref();
    $alias = 42;
    var_dump(value_ref());

    $localAlias =& local_ref();
    $localAlias = 'kept alive';
    var_dump($localAlias);

    eval('$evalAlias =& value_ref(); $evalAlias = "from eval";');
    var_dump(value_ref());

    require __DIR__ . '/function-return-reference-require.inc';
    var_dump(value_ref());
}

function &local_ref()
{
    $value = 1;
    return $value;
}
?>
--EXPECT--
int(42)
string(10) "kept alive"
string(9) "from eval"
string(12) "from require"
