--TEST--
std map: UnsafePtr unsafe_cast
--FILE--
<?php
function std_map_unsafe_ptr_update(UnsafePtr $unsafePtr): void
{
    $map = std::unsafe_cast(std::map(complex_types::type_str, native_types::type_int), $unsafePtr);
    var_dump($map["b"]);
    $map["c"] = 9;

    $GLOBALS['unsafe_ptr'] = $unsafePtr;
    var_dump($GLOBALS['unsafe_ptr']);

    unset($unsafePtr);

    $array = (array)$map;
    var_dump(count($array));
}

function main() {
    $map = std::map(complex_types::type_str, native_types::type_int);
    $map["a"] = 1;
    $map["b"] = 7;
    $map["c"] = 3;

    std_map_unsafe_ptr_update($map);
    var_dump($map["c"]);
}
?>
--EXPECT--
int(7)
NULL
int(3)
int(9)
