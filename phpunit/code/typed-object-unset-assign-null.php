<?php

class TypedObjectUnsetNullValue
{
}

function main(): void
{
    $value = new TypedObjectUnsetNullValue();
    unset($value);
    $value = null;
}
