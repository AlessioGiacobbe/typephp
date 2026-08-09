--TEST--
unset typed object reads as null and accepts a valid reassignment
--FILE--
<?php
class UnsetTypedObjectValue
{
    public int $number = 1;

    public function value(): string
    {
        return 'value';
    }

    public function readProperty(): int
    {
        return $this->number;
    }
}

function makeUnsetTypedObjectValue(): UnsetTypedObjectValue
{
    return new UnsetTypedObjectValue();
}

function main()
{
    $value = makeUnsetTypedObjectValue();
    unset($value);

    var_dump(@$value === null);
    var_dump(@$value instanceof UnsetTypedObjectValue);
    var_dump(isset($value));

    try {
        @$value->readProperty();
    } catch (Throwable $error) {
        echo $error::class, "\n";
    }

    $value = makeUnsetTypedObjectValue();
    var_dump($value->value());
}
?>
--EXPECT--
bool(true)
bool(false)
bool(false)
Error
string(5) "value"
