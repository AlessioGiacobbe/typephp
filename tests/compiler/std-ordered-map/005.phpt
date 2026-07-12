--TEST--
std ordered_map: unsafe_cast
--FILE--
<?php
function std_map_unsafe_ptr_update($source): void
{
    $map = $source->toStdOrderedMap(complex_types::type_str, native_types::type_int);
    var_dump($map["b"]);
    $map["c"] = 9;

    unset($source);

    $array = (array)$map;
    var_dump(count($array));
}

function main() {
    $map = std::ordered_map(complex_types::type_str, native_types::type_int);
    $map["a"] = 1;
    $map["b"] = 7;
    $map["c"] = 3;

    std_map_unsafe_ptr_update($map);
    var_dump($map["c"]);
}
?>
--EXPECT--
int(7)
int(3)
int(9)
