--TEST--
std vector: unsafe_cast type mismatch
--FILE--
<?php
function std_vector_unsafe_ptr_type_mismatch(UnsafePtr $unsafePtr): void
{
    $vector = std::unsafe_cast(std::vector(native_types::type_float), $unsafePtr);
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
std::unsafe_cast(): UnsafePtr type mismatch
