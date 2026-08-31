<?php

declare(strict_types=1);

use TypePhpIntegration\Library\Counter;
use function TypePhpIntegration\Library\add;

function main(): void
{
    echo add(19, 23), "\n";

    $counter = new Counter();
    $counter->add(3);
    $counter->add(4);
    echo 'counter=', $counter->value, "\n";
}
