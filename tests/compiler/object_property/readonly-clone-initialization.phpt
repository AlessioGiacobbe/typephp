--TEST--
TypePHP readonly properties may be updated while cloning
--FILE--
<?php

use native_types;

class ReadonlyCloneBase
{
    public readonly int $base;

    public function __construct()
    {
        $this->base = 1;
    }

    public function __clone(): void
    {
        $this->base++;
        $this->base += 3;
    }
}

class ReadonlyCloneValue extends ReadonlyCloneBase
{
    public readonly string $name;
    public readonly array $items;

    public function __construct()
    {
        parent::__construct();
        $this->name = 'original';
        $this->items = [1];
    }

    public function __clone(): void
    {
        parent::__clone();
        $this->name = 'clone';
        $this->name .= 'd';
        $this->items[] = 2;
        $this->items[0] = 10;
    }
}

function main(): void
{
    $original = new ReadonlyCloneValue();
    $copy = clone $original;
    var_dump($original->base, $original->name, $original->items);
    var_dump($copy->base, $copy->name, $copy->items);
}
?>
--EXPECT--
int(1)
string(8) "original"
array(1) {
  [0]=>
  int(1)
}
int(5)
string(6) "cloned"
array(2) {
  [0]=>
  int(10)
  [1]=>
  int(2)
}
