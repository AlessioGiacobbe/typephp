--TEST--
Dynamic toArray() dispatch supports real methods and __call
--FILE--
<?php

class DynamicToArrayValue
{
    public function toArray(): array
    {
        return ['value' => 42];
    }
}

class DynamicToArrayMagicOnly
{
    public function __call(string $name, array $arguments): array
    {
        return ['magic' => $name];
    }
}

function eraseToMixed(object $value): mixed
{
    return $value;
}

function callDynamicToArray(mixed $value): array
{
    return $value->toArray();
}

function dumpDynamicToArrayError(object $value): void
{
    try {
        callDynamicToArray(eraseToMixed($value));
    } catch (Error $error) {
        echo $error->getMessage(), "\n";
    }
}

function main(): void
{
    var_dump(callDynamicToArray(eraseToMixed(new DynamicToArrayValue())));
    dumpDynamicToArrayError(new stdClass());
    var_dump((new DynamicToArrayMagicOnly())->toArray());
    var_dump(callDynamicToArray(eraseToMixed(new DynamicToArrayMagicOnly())));

    $plain = new stdClass();
    $plain->value = 7;
    var_dump((array) $plain);
}
?>
--EXPECT--
array(1) {
  ["value"]=>
  int(42)
}
Invalid callback stdClass::toArray, class stdClass does not have a method "toArray"
array(1) {
  ["magic"]=>
  string(7) "toArray"
}
array(1) {
  ["magic"]=>
  string(7) "toArray"
}
array(1) {
  ["value"]=>
  int(7)
}
