<?php
class Foo {
    public string $a;
    public int $b;

    public function __construct(string $a, int $b) {
        $this->a = $a;
        $this->b = $b;
    }
}

function main()
{
    $map = std::map(complex_types::type_str, Foo::class);
    $map["a"] = new Foo("a", 1);
    $map["b"] = new Foo("b", 2);
    var_dump($map);
}