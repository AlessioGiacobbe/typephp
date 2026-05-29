--TEST--
std array: unsafe_cast type mismatch
--FILE--
<?php
function std_array_unsafe_ptr_type_mismatch($source): void
{
    $array = std::unsafe_cast(std::array(native_types::type_float, 3), $source);
}

function main() {
    $array = std::array(native_types::type_int, 3);
    try {
        std_array_unsafe_ptr_type_mismatch($array);
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
std::unsafe_cast(): std container type mismatch
