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

function clearGlobal(): void
{
    global $nativeGlobal;
    $nativeGlobal = null;
}

function globalIsNull(): bool
{
    global $nativeGlobal;
    return is_null($nativeGlobal);
}

function initializeGlobalsArray(): void
{
    $GLOBALS['nativeGlobalsArray'] ??= new NativeCounter();
    $GLOBALS['nativeGlobalsArray']->value = 42;
}

function readGlobalsArray(): int
{
    return $GLOBALS['nativeGlobalsArray']->value;
}

function resetGlobalsArray(): void
{
    $GLOBALS['nativeGlobalsArray'] = null;
}

function globalsArrayIsNull(): bool
{
    return is_null($GLOBALS['nativeGlobalsArray']);
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
    clearGlobal();
    var_dump(globalIsNull());
    replaceGlobalWithChild();
    var_dump(readGlobal());
    initializeGlobalsArray();
    var_dump(readGlobalsArray());
    resetGlobalsArray();
    var_dump(globalsArrayIsNull());
    initializeGlobalsArray();
    var_dump(readGlobalsArray());
    var_dump(nextStatic());
    var_dump(nextStatic());
}
?>
--EXPECT--
int(40)
int(41)
bool(true)
int(41)
int(42)
bool(true)
int(42)
int(1)
int(2)
