--TEST--
Return type covariance: union narrowing and object subtype
--FILE--
<?php

interface UnionReturnContract
{
    public function make(): int|string;
}

class UnionReturnImpl implements UnionReturnContract
{
    // Covariant: narrowing a union return type (int|string -> int) is allowed.
    public function make(): int
    {
        return 42;
    }
}

class BaseType {}
class ChildType extends BaseType {}

interface ObjectReturnContract
{
    public function build(): BaseType;
}

class ObjectReturnImpl implements ObjectReturnContract
{
    // Covariant: returning a subtype (ChildType) for a BaseType return is allowed.
    public function build(): ChildType
    {
        return new ChildType();
    }
}

function main()
{
    $impl = new UnionReturnImpl();
    var_dump($impl->make());

    $obj = new ObjectReturnImpl();
    $built = $obj->build();
    var_dump($built instanceof BaseType);
    var_dump($built instanceof ChildType);
}
?>
--EXPECT--
int(42)
bool(true)
bool(true)
