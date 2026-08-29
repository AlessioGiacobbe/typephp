<?php
function main(): void
{
    $a = 1;

    echo count([1, 2, 3]), "\n";
    echo count([[1, 2], [3]]), "\n";
    echo count([$a, -2, true, null]), "\n";
    echo count([]), "\n";
}
