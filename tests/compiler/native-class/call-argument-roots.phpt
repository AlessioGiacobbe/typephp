--TEST--
Native class: call result arguments are evaluated left-to-right and precisely rooted
--FILE--
<?php

#[Native]
class NativeArgument
{
    public string $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }
}

function makeNativeArgument(string $name): NativeArgument
{
    echo 'make:', $name, PHP_EOL;
    return new NativeArgument($name);
}

function consumeNativeArguments(NativeArgument $first, NativeArgument $second): void
{
    echo $first->name, ':', $second->name, PHP_EOL;
}

function main(): void
{
    consumeNativeArguments(makeNativeArgument('A'), makeNativeArgument('B'));
}
?>
--EXPECT--
make:A
make:B
A:B
