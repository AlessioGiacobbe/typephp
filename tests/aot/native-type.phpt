--TEST--
native type
--FILE--
<?php
function main()
{
    $a = std::int(100);
    var_dump($a);

    $b = std::float(100.0);
    var_dump($b);

    $c = std::bool(true);
    var_dump($c);
}
?>
--EXPECT--
int(100)
float(100)
bool(true)
