--TEST--
Native class: long-running allocation triggers automatic tracing collection
--FILE--
<?php

#[Native]
class NativeGcThresholdValue
{
    // Keep the pressure in the object payload so this test does not depend on
    // the implementation size of the hidden Native GC header.
    public int $padding1;
    public int $padding2;
    public int $padding3;

    public function __destruct()
    {
        global $nativeGcFinalized;
        $nativeGcFinalized++;
    }
}

function main(): void
{
    global $nativeGcFinalized;
    $nativeGcFinalized = 0;
    for ($i = 0; $i < 300000; $i++) {
        $value = new NativeGcThresholdValue();
    }
    var_dump($nativeGcFinalized > 0);
}

?>
--EXPECT--
bool(true)
