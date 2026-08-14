--TEST--
Native class: keyword conversions lower to exactly typed native methods
--FILE--
<?php

#[Native]
class NativeConversions
{
    public int $value = 7;

    public function toArray(): array
    {
        return [$this->value];
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function toFloat(): float
    {
        return $this->value + 0.5;
    }

    public function toBool(): bool
    {
        return $this->value !== 0;
    }

    public function toString(): string
    {
        return 'value=' . $this->value;
    }
}

#[Native]
class NativeMagicString
{
    public function __toString(): string
    {
        return 'magic';
    }
}

function main(): void
{
    $value = new NativeConversions();
    var_dump($value->toArray());
    var_dump($value->toInt());
    var_dump($value->toFloat());
    var_dump($value->toBool());
    var_dump($value->toString());
    var_dump((string) $value);
    var_dump(strval($value));

    $magic = new NativeMagicString();
    var_dump($magic->toString());
    var_dump((string) $magic);
}
?>
--EXPECT--
array(1) {
  [0]=>
  int(7)
}
int(7)
float(7.5)
bool(true)
string(7) "value=7"
string(7) "value=7"
string(7) "value=7"
string(5) "magic"
string(5) "magic"
