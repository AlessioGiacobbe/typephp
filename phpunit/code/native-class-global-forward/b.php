<?php

#[Native]
class NativeForwardGlobalValue
{
    public int $value = 42;
}

class NativeForwardGlobalFactory
{
    public static function create(): NativeForwardGlobalValue
    {
        return new NativeForwardGlobalValue();
    }

    public static function initialize(): void
    {
        global $nativeForwardGlobal;
        $local = self::create();
        $nativeForwardGlobal = $local;
    }
}
