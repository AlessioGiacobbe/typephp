--TEST--
Readonly constructor cannot be called dynamically after construction
--FILE--
<?php

class ReadonlyDynamicConstructorCall
{
    public readonly int $value;

    public function __construct(int $value)
    {
        $this->value = $value;
    }
}

function main(): void
{
    $value = new ReadonlyDynamicConstructorCall(1);
    $method = '__construct';
    try {
        $value->$method(2);
    } catch (Error $error) {
        echo $error->getMessage(), "\n";
    }
    var_dump($value->value);
}
?>
--EXPECT--
Constructor ReadonlyDynamicConstructorCall::__construct() can only be invoked by new
int(1)
