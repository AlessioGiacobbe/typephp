--TEST--
unset typed object invalidates native-call assumptions and reads as null
--FILE--
<?php
class UnsetTypedObjectValue
{
    public function value(): string
    {
        return 'value';
    }
}

function makeUnsetTypedObjectValue(): UnsetTypedObjectValue
{
    return new UnsetTypedObjectValue();
}

function acceptUnsetTypedObjectValue(UnsetTypedObjectValue $value): string
{
    echo "entered\n";
    return $value->value();
}

function main()
{
    $value = makeUnsetTypedObjectValue();
    unset($value);

    var_dump(@$value === null);
    var_dump(@$value instanceof UnsetTypedObjectValue);
    var_dump(isset($value));

    try {
        acceptUnsetTypedObjectValue(@$value);
    } catch (Throwable $error) {
        echo $error::class, "\n";
    }

    try {
        @$value->value();
    } catch (Throwable $error) {
        echo $error::class, "\n";
    }

    $value = makeUnsetTypedObjectValue();
    var_dump(acceptUnsetTypedObjectValue($value));
    var_dump($value->value());
}
?>
--EXPECT--
bool(true)
bool(false)
bool(false)
TypeError
Error
entered
string(5) "value"
string(5) "value"
