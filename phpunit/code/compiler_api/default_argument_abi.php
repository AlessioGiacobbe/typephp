<?php

const EXPORTED_ABI_INT = 42;
const EXPORTED_ABI_STRING = 'internal';
const EXPORTED_ABI_ARRAY = ['mode' => 'fast'];

function exported_defaults(
    string $text = 'hello',
    array $options = ['mode' => 'fast'],
    mixed $value = null,
    int $count = 0,
    bool $enabled = false
): array {
    return [$text, $options, $value, $count, $enabled];
}

function exported_variadic(string ...$values): array {
    return $values;
}
