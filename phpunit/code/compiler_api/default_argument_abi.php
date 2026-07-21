<?php

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
