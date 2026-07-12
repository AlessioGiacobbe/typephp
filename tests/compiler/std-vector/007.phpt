--TEST--
std vector: unsafe_cast type mismatch
--FILE--
<?php
function std_vector_unsafe_ptr_type_mismatch($source): void
{
    $vector = $source->toStdVector(native_types::type_float);
}

function main() {
    $vector = std::vector(native_types::type_int, 3);
    try {
        std_vector_unsafe_ptr_type_mismatch($vector);
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
std container type mismatch
