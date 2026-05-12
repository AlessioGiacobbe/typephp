--TEST--
std unordered map: unsafe_cast type mismatch
--FILE--
<?php
function std_unordered_map_unsafe_ptr_type_mismatch(UnsafePtr $unsafePtr): void
{
    $map = std::unsafe_cast(std::unordered_map(native_types::type_int, native_types::type_float), $unsafePtr);
}

function main() {
    $map = std::unordered_map(native_types::type_int, native_types::type_int);
    try {
        std_unordered_map_unsafe_ptr_type_mismatch($map);
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
std::unsafe_cast(): UnsafePtr type mismatch
