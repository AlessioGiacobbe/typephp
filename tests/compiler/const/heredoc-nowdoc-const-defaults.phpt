--TEST--
global constants, property defaults and parameter defaults with heredoc/nowdoc syntax
--FILE--
<?php

const G_HEREDOC = <<<ABC
    abc
    ABC;
const G_NOWDOC = <<<'DEF'
    def
    DEF;

class WithProp
{
    public string $p = <<<ABC
    xyz
    ABC;
}

function with_default(string $x = <<<ABC
abc
ABC): string {
    return $x;
}

function main()
{
    var_dump(G_HEREDOC, G_NOWDOC);
    $o = new WithProp();
    var_dump($o->p);
    var_dump(with_default());
}
?>
--EXPECT--
string(3) "abc"
string(3) "def"
string(3) "xyz"
string(3) "abc"
