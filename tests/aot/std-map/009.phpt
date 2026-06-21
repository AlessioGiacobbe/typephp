--TEST--
std map: unset
--FILE--
<?php
function main() {
    $map = std::map(complex_types::type_string, native_types::type_int);
    $map["alpha"] = 10;
    $map["beta"] = 20;
    var_dump($map);
    unset($map["alpha"]);
    var_dump($map);
}
?>
--EXPECT--
array(2) {
  ["beta"]=>
  int(20)
  ["alpha"]=>
  int(10)
}
array(1) {
  ["beta"]=>
  int(20)
}