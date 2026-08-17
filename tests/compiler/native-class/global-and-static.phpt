--TEST--
Native class: TypePHP globals and static locals retain request-rooted native objects
--FILE--
<?php

#[Native]
class NativeCounter
{
    public int $value;
}

#[Native]
class NativeCounterChild extends NativeCounter {}

function initializeGlobal(): void
{
    global $nativeGlobal;
    $nativeGlobal = new NativeCounter();
    $nativeGlobal->value = 40;
}

function readGlobal(): int
{
    global $nativeGlobal;
    return $nativeGlobal->value;
}

function replaceGlobalWithChild(): void
{
    global $nativeGlobal;
    $nativeGlobal = new NativeCounterChild();
    $nativeGlobal->value = 41;
}

function nextStatic(): int
{
    static $counter = new NativeCounter();
    return ++$counter->value;
}

function main(): void
{
    initializeGlobal();
    var_dump(readGlobal());
    replaceGlobalWithChild();
    var_dump(readGlobal());
    var_dump(nextStatic());
    var_dump(nextStatic());
}
?>
--EXPECT--
int(40)
int(41)
int(1)
int(2)
