--TEST--
std array: UnsafePtr unsafe_cast
--FILE--
<?php
function std_array_unsafe_ptr_update(UnsafePtr $unsafePtr): void
{
    $array = std::unsafe_cast(std::array(native_types::type_int, 3), $unsafePtr);
    var_dump($array[1]);
    $array[2] = 9;
}

function main() {
    $array = std::array(native_types::type_int, 3);
    $array[0] = 1;
    $array[1] = 7;
    $array[2] = 3;

    std_array_unsafe_ptr_update($array);
    var_dump($array[2]);
}
?>
--EXPECT--
int(7)
int(9)
