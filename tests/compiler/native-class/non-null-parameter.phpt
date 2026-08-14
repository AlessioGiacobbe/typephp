--TEST--
Native class: non-null parameters are validated at function entry
--FILE--
<?php

#[Native]
class NativeRequiredArgument
{
}

function acceptRequiredNative(NativeRequiredArgument $value): void
{
    echo "entered\n";
}

function main(): void
{
    $value = new NativeRequiredArgument();
    unset($value);
    try {
        acceptRequiredNative($value);
    } catch (Error $error) {
        echo "rejected\n";
    }
}
?>
--EXPECT--
rejected
