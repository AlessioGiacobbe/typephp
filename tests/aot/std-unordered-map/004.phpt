--TEST--
std unordered map: exact class value type
--FILE--
<?php
class StdUnorderedMapClassValue
{
    public function __construct(public int $value)
    {
    }

    public function getValue(): int
    {
        return $this->value;
    }
}

class StdUnorderedMapClassValueChild extends StdUnorderedMapClassValue
{
}

function std_unordered_map_class_value_mixed(mixed $value): mixed
{
    return $value;
}

function main() {
    $map = std::unordered_map(complex_types::type_str, StdUnorderedMapClassValue::class);
    $map["a"] = new StdUnorderedMapClassValue(1);
    var_dump($map["a"]->getValue());

    $map["b"] = std_unordered_map_class_value_mixed(new StdUnorderedMapClassValue(2));
    var_dump($map["b"]->getValue());

    try {
        $map["c"] = std_unordered_map_class_value_mixed(new StdUnorderedMapClassValueChild(3));
    } catch (Throwable $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
int(1)
int(2)
The parameter `object` must be instance of class `StdUnorderedMapClassValue`, object of `StdUnorderedMapClassValueChild` given
