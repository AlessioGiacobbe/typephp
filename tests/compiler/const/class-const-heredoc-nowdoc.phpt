--TEST--
class constants with heredoc and nowdoc syntax
--FILE--
<?php

class Test
{
    const VALUE1 = <<<ABC
    abc
    ABC;
    const VALUE2 = <<<'DEF'
    def
    DEF;
}

function main()
{
    var_dump(Test::VALUE1, Test::VALUE2);
}
?>
--EXPECT--
string(3) "abc"
string(3) "def"
