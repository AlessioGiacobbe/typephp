--TEST--
std map: unsafe_cast type mismatch
--FILE--
<?php
function std_map_unsafe_ptr_type_mismatch(UnsafePtr $unsafePtr): void
{
    $map = std::unsafe_cast(std::map(complex_types::type_str, native_types::type_float), $unsafePtr);
}

function main() {
    $map = std::map(complex_types::type_str, native_types::type_int);
    $ptr = std::unsafe_ptr($map);

    try {
        std_map_unsafe_ptr_type_mismatch($ptr);
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
std::unsafe_cast(): UnsafePtr type mismatch
