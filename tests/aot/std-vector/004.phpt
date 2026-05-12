--TEST--
std vector: exact class value type checks typed parameter at runtime
--FILE--
<?php
class StdVectorRuntimeClassValue
{
    public function __construct(public int $value)
    {
    }
}

class StdVectorRuntimeClassValueChild extends StdVectorRuntimeClassValue
{
}

function std_vector_runtime_class_value(StdVectorRuntimeClassValue $value): void
{
    $vector = std::vector(StdVectorRuntimeClassValue::class);

    try {
        $vector[] = $value;
    } catch (Throwable $e) {
        echo $e->getMessage(), "\n";
    }
}

function main() {
    std_vector_runtime_class_value(new StdVectorRuntimeClassValueChild(1));
}
?>
--EXPECT--
The parameter `object` must be instance of class `StdVectorRuntimeClassValue`, object of `StdVectorRuntimeClassValueChild` given
