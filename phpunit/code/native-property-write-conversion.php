<?php
use native_types;

class NativePropertyWriteConversionBox
{
    public int $value = 0;
}

function native_property_write_conversion(NativePropertyWriteConversionBox $box, int $nativeValue, $dynamicValue): void
{
    $box->value = $nativeValue;
    $box->value = $dynamicValue;
}
