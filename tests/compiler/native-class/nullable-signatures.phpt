--TEST--
Native class: nullable parameters and returns stay native pointers
--FILE--
<?php

#[Native]
class NativeNullableValue
{
    public int $value = 42;
}

function maybeNative(bool $create): ?NativeNullableValue
{
    if ($create) {
        return new NativeNullableValue();
    }
    return null;
}

function readMaybeNative(?NativeNullableValue $value): int
{
    if ($value === null) {
        return -1;
    }
    return $value->value;
}

function maybeNativeUnion(bool $create): NativeNullableValue|null
{
    return $create ? new NativeNullableValue() : null;
}

function readMaybeNativeUnion(NativeNullableValue|null $value): int
{
    return $value === null ? -2 : $value->value;
}

function main(): void
{
    var_dump(readMaybeNative(maybeNative(false)));
    var_dump(readMaybeNative(maybeNative(true)));
    var_dump(readMaybeNativeUnion(maybeNativeUnion(false)));
    var_dump(readMaybeNativeUnion(maybeNativeUnion(true)));
}
?>
--EXPECT--
int(-1)
int(42)
int(-2)
int(42)
