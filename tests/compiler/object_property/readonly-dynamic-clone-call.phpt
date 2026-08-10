--TEST--
Readonly clone method cannot be called dynamically
--FILE--
<?php

class ReadonlyDynamicCloneCall
{
    public readonly int $value;

    public function __construct()
    {
        $this->value = 1;
    }

    public function __clone(): void
    {
        $this->value = 2;
    }
}

function main(): void
{
    $value = new ReadonlyDynamicCloneCall();
    $method = '__clone';
    try {
        $value->$method();
    } catch (Error $error) {
        echo $error->getMessage(), "\n";
    }
    var_dump($value->value);
}
?>
--EXPECT--
Clone method ReadonlyDynamicCloneCall::__clone() can only be invoked by clone
int(1)
