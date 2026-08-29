<?php
function bump(): int
{
    echo "bump\n";
    return 1;
}

function main(): void
{
    $rest = [1, 2, 3, 4, 5];
    $i = 0;

    echo count([bump(), bump()]), "\n";
    echo count(['a' => 1, 'a' => 2]), "\n";
    echo count([...$rest, 9]), "\n";
    echo count([$i++, $i++]), "\n";
}
