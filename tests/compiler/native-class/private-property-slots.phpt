--TEST--
Native class: parent and child private properties use independent native slots
--FILE--
<?php

#[Native]
class NativePrivateBase
{
    private int $value = 10;

    public function baseValue(): int
    {
        return $this->value;
    }

    public function setBaseValue(int $value): void
    {
        $this->value = $value;
    }
}

#[Native]
class NativePrivateChild extends NativePrivateBase
{
    private int $value = 20;

    public function childValue(): int
    {
        return $this->value;
    }

    public function setChildValue(int $value): void
    {
        $this->value = $value;
    }
}

function main(): void
{
    $value = new NativePrivateChild();
    var_dump($value->baseValue(), $value->childValue());
    $value->setBaseValue(11);
    $value->setChildValue(22);
    var_dump($value->baseValue(), $value->childValue());
}
?>
--EXPECT--
int(10)
int(20)
int(11)
int(22)
