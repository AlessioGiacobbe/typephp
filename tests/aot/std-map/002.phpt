--TEST--
std map: string key
--FILE--
<?php
function main() {
    $map = std::map(complex_types::type_string, native_types::type_int);
    $map["alpha"] = 10;
    $map["beta"] = 20;
    $map["beta"] += 22;

    $key = "alpha";
    var_dump($map[$key]);
    var_dump($map["beta"]);
    var_dump(count($map));

    $map2 = std::map(complex_types::type_str, native_types::type_float);
    $map2["pi"] = 3.14;
    var_dump($map2["pi"] == 3.14);
}
?>
--EXPECT--
int(10)
int(42)
int(2)
bool(true)
