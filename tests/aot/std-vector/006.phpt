--TEST--
std vector: UnsafePtr unsafe_cast
--FILE--
<?php
function std_vector_unsafe_ptr_update(UnsafePtr $unsafePtr): void
{
    $vector = std::unsafe_cast(std::vector(native_types::type_int), $unsafePtr);
    var_dump($vector[1]);
    $vector[2] = 9;
}

function main() {
    $vector = std::vector(native_types::type_int, 3);
    $vector[0] = 1;
    $vector[1] = 7;
    $vector[2] = 3;

    $ptr = std::unsafe_ptr($vector);
    std_vector_unsafe_ptr_update($ptr);
    var_dump($vector[2]);
}
?>
--EXPECT--
int(7)
int(9)
