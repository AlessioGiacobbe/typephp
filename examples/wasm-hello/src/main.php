<?php

declare(strict_types=1);

function main(int $argc, array $argv): void
{
    $stdin = stream_get_contents(STDIN);
    $report = WasiDemo::report($argc, $argv, $stdin === false ? '' : $stdin);
    echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
