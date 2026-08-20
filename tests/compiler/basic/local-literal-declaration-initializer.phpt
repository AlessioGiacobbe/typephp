--TEST--
Top-level literal local assignments preserve PHP values
--FILE--
<?php

function values(): array
{
    $integer = 42;
    $negative = -7;
    $floating = 1.25;
    $boolean = true;
    $string = 'hello';
    $nullValue = null;

    return [$integer, $negative, $floating, $boolean, $string, $nullValue];
}

var_dump(values());
?>
--EXPECT--
array(6) {
  [0]=>
  int(42)
  [1]=>
  int(-7)
  [2]=>
  float(1.25)
  [3]=>
  bool(true)
  [4]=>
  string(5) "hello"
  [5]=>
  NULL
}
