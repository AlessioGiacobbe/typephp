--TEST--
TypePHP readonly properties are mutable only during their declaring constructor
--FILE--
<?php

use native_types;

class ReadonlyConstructorOnly
{
    public readonly int $number;
    public readonly string $text;
    public readonly array $items;

    public function __construct()
    {
        $this->number = 1;
        $this->number = 2;
        $this->number += 3;
        ++$this->number;

        $this->text = 'a';
        $this->text .= 'b';

        $this->items = [];
        $this->items[] = 10;
        $this->items[0] = 20;
    }
}

function main(): void
{
    $value = new ReadonlyConstructorOnly();
    var_dump($value->number, $value->text, $value->items);
}
?>
--EXPECT--
int(6)
string(2) "ab"
array(1) {
  [0]=>
  int(20)
}
