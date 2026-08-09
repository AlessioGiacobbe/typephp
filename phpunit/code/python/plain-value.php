<?php

function main(): void
{
    $list = python\list([1, 2, 3]);
    $plain = $list->toPlainValue();
    $dynamic = convertPlainValue($list);
    $array = $dynamic->toArray();
    var_dump($plain, $array);
}

function convertPlainValue(mixed $value): mixed
{
    return $value->toPlainValue();
}
