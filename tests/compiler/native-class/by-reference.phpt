--TEST--
Native class: by-reference parameters replace the caller pointer
--FILE--
<?php

#[Native]
class NativeReferenceValue
{
    public int $value;

    public function __construct(int $value)
    {
        $this->value = $value;
    }
}

function replaceNativeReference(NativeReferenceValue &$value): void
{
    $value = new NativeReferenceValue(42);
}

function main(): void
{
    $value = new NativeReferenceValue(1);
    replaceNativeReference($value);
    var_dump($value->value);
}
?>
--EXPECT--
int(42)
