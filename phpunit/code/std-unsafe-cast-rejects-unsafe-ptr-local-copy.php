<?php

function std_unsafe_cast_rejects_unsafe_ptr_local_copy(UnsafePtr $unsafePtr): void
{
    $ptr = $unsafePtr;
    $array = std::unsafe_cast(std::array(native_types::type_int, 3), $ptr);
}
