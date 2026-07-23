--TEST--
Typed parameter default value from an unresolvable (external) class constant
--FILE--
<?php

class TypedDefault
{
    // \ArrayObject is an internal (external) class whose constants cannot be folded
    // at compile time, so the default is emitted as php::constant(...). The parameter
    // type `int` maps to php::Int, which cannot be copy-initialized from the
    // php::Variant returned by php::constant(...). The compiler must wrap the default
    // in php::Int(...) so the generated C++ compiles.
    public function run(int $value = \ArrayObject::ARRAY_AS_PROPS)
    {
        var_dump($value);
    }
}

function main()
{
    (new TypedDefault)->run();
}
?>
--EXPECT--
int(2)
