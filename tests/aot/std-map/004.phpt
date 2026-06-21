--TEST--
std map: exact class value type
--FILE--
<?php
class StdMapClassValue
{
    public function __construct(public int $value)
    {
    }

    public function getValue(): int
    {
        return $this->value;
    }
}

class StdMapClassValueChild extends StdMapClassValue
{
}

function std_map_class_value_mixed(mixed $value): mixed
{
    return $value;
}

function main() {
    $map = std::map(complex_types::type_str, StdMapClassValue::class);
    $map["a"] = new StdMapClassValue(1);
    var_dump($map["a"]->getValue());

    $map["b"] = std_map_class_value_mixed(new StdMapClassValue(2));
    var_dump($map["b"]->getValue());

    try {
        $map["c"] = std_map_class_value_mixed(new StdMapClassValueChild(3));
    } catch (Throwable $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
int(1)
int(2)
The parameter `object` must be instance of class `StdMapClassValue`, object of `StdMapClassValueChild` given
