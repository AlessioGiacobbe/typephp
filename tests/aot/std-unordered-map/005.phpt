--TEST--
std unordered map: UnsafePtr unsafe_cast
--FILE--
<?php
function std_unordered_map_unsafe_ptr_update(UnsafePtr $unsafePtr): void
{
    $map = std::unsafe_cast(std::unordered_map(native_types::type_int, native_types::type_int), $unsafePtr);
    var_dump($map[2]);
    $map[3] = 9;
}

function main() {
    $map = std::unordered_map(native_types::type_int, native_types::type_int);
    $map[1] = 1;
    $map[2] = 7;
    $map[3] = 3;

    $ptr = std::unsafe_ptr($map);
    std_unordered_map_unsafe_ptr_update($ptr);
    var_dump($map[3]);
}
?>
--EXPECT--
int(7)
int(9)
