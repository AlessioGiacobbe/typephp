--TEST--
Native class: ternary, match and coalesce preserve native pointer types
--FILE--
<?php

#[Native]
class NativeSelectedValue
{
    public int $value;

    public function __construct(int $value)
    {
        $this->value = $value;
    }
}

function selectWithMatch(int $kind): NativeSelectedValue
{
    return match ($kind) {
        1 => new NativeSelectedValue(10),
        default => new NativeSelectedValue(20),
    };
}

function selectWithCoalesce(?NativeSelectedValue $value): NativeSelectedValue
{
    return $value ?? new NativeSelectedValue(30);
}

function main(): void
{
    $first = true ? new NativeSelectedValue(1) : new NativeSelectedValue(2);
    var_dump($first->value);
    var_dump(selectWithMatch(1)->value, selectWithMatch(2)->value);
    var_dump(selectWithCoalesce(null)->value);
    var_dump(selectWithCoalesce($first)->value);
}
?>
--EXPECT--
int(1)
int(10)
int(20)
int(30)
int(1)
