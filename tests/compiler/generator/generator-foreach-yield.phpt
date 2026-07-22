--TEST--
generator re-yielding array elements via foreach with \Generator return type
--FILE--
<?php

function main()
{
    $g = test([1, 2, 3]);
    var_dump($g);
    foreach ($g as $value)
    {
        var_dump($value);
    }
}

function test(array $array): \Generator
{
    foreach ($array as $value)
    {
        yield $value;
    }
}

// main();
?>
--EXPECTF--
object(FiberGenerator)#%d (9) {
  ["callback":"FiberGenerator":private]=>
  object(Closure)#%d (2) {
    ["function"]=>
    string(19) "stdClass::{closure}"
    ["this"]=>
    object(stdClass)#%d (1) {
      ["box"]=>
      resource(%d) of type (php::box)
    }
  }
  ["fiber":"FiberGenerator":private]=>
  NULL
  ["current":"FiberGenerator":private]=>
  NULL
  ["key":"FiberGenerator":private]=>
  NULL
  ["valid":"FiberGenerator":private]=>
  bool(false)
  ["state":"FiberGenerator":private]=>
  int(0)
  ["yield_count":"FiberGenerator":private]=>
  int(0)
  ["next_index":"FiberGenerator":private]=>
  int(0)
  ["return_value":"FiberGenerator":private]=>
  NULL
}
int(1)
int(2)
int(3)
