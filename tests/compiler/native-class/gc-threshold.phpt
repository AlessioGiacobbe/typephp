--TEST--
Native class: long-running allocation triggers automatic tracing collection
--FILE--
<?php

#[Native]
class NativeGcThresholdValue
{
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
