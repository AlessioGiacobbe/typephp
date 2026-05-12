<?php

function std_unsafe_ptr_parameter_cannot_be_reassigned(UnsafePtr $unsafePtr): void
{
    $unsafePtr = null;
}
