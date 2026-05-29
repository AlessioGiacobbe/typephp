--TEST--
std map: unsafe_cast type mismatch
--FILE--
<?php
function std_map_unsafe_ptr_type_mismatch($source): void
{
    $map = std::unsafe_cast(std::map(complex_types::type_str, native_types::type_float), $source);
}

function main() {
    $map = std::map(complex_types::type_str, native_types::type_int);
    try {
        std_map_unsafe_ptr_type_mismatch($map);
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
std::unsafe_cast(): std container type mismatch
