<?php
function phpunit_multi_values(): array
{
    $first = 1;
    $second = 'two';
    return [$first, $second];
}

function phpunit_multi_consumer(): void
{
    [$first, $second] = phpunit_multi_values();
    $array = phpunit_multi_values();
}

function phpunit_multi_side_effect(): array
{
    return [time(), 2];
}
