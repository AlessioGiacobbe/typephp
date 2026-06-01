--TEST--
std unordered map: unsafe_cast type mismatch
--FILE--
<?php
function std_unordered_map_unsafe_ptr_type_mismatch($source): void
{
    $map = $source->toStdUnorderedMap(native_types::type_int, native_types::type_float);
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
std container type mismatch
