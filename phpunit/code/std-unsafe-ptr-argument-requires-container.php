<?php

function std_unsafe_ptr_accepts_container_only(UnsafePtr $unsafePtr): void
{
}

function test_std_unsafe_ptr_argument_requires_container(): void
{
    $value = 1;
    std_unsafe_ptr_accepts_container_only($value);
}
