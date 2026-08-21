<?php

use native_types;

function hotPathTrace(string $value): string
{
    return $value;
}

function hotPathCodegen(int $limit): int
{
    $items = [];
    $other = [2];
    $value = 1;

    $items[0] = $value;
    $items[] = $value;
    $items[0] += $value;
    $items[0] += $other[0];
    $items[2] = $other[0];

    $assigned = ($items[1] = $value);
    $assigned += ($items[0] += $value);

    $safe = 'item-' . $limit;
    $ordered = hotPathTrace('left') . hotPathTrace('right');
    $length = strlen('item-' . $limit);

    while ($limit-- > 0) {
        ++$value;
    }

    return $assigned + $length + strlen($safe) + strlen($ordered) + $value;
}
