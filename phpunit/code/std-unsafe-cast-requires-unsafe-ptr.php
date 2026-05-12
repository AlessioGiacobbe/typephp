<?php

function std_unsafe_cast_requires_unsafe_ptr(mixed $ptr): void
{
    $array = std::unsafe_cast(std::array(native_types::type_int, 3), $ptr);
}
