<?php

declare(strict_types=1);

use native_types;

final class WasiDemo
{
    public static function report(int $argc, array $argv, string $stdin): array
    {
        $greeting = getenv('DEMO_GREETING');
        if ($greeting === false) {
            $greeting = 'Hello from the WASI environment';
        }

        return [
            'runtime' => [
                'php' => PHP_VERSION,
                'platform' => php_uname(),
                'integerBits' => PHP_INT_SIZE * 8,
            ],
            'clock' => [
                'timestamp' => time(),
                'iso8601' => date('Y-m-d H:i:s T'),
                'microtime' => microtime(true),
            ],
            'random' => [
                'integer' => random_int(100000, 999999),
                'token' => bin2hex(random_bytes(8)),
            ],
            'input' => [
                'argc' => $argc,
                'argv' => $argv,
                'greeting' => $greeting,
                'stdin' => trim($stdin),
            ],
            'filesystem' => self::filesystemReport(),
            'precision' => self::precisionReport(),
            'capabilities' => [
                'supported' => ['arguments', 'environment', 'stdin/stdout/stderr', 'clock', 'random', 'filesystem'],
                'disabled' => ['network', 'process', 'signals', 'shell', 'Fiber', 'Generator'],
            ],
        ];
    }

    private static function filesystemReport(): array
    {
        $directory = '/workspace';
        if (!is_dir($directory)) {
            mkdir($directory);
        }

        $counterFile = $directory . '/run-count.txt';
        $counter = 0;
        if (file_exists($counterFile)) {
            $counter = (int) trim((string) file_get_contents($counterFile));
        }
        $counter++;
        file_put_contents($counterFile, (string) $counter);

        $messageFile = $directory . '/hello.txt';
        $message = 'TypePHP wrote this file during browser run #' . $counter;
        file_put_contents($messageFile, $message);

        $files = scandir($directory);
        if ($files === false) {
            $files = [];
        }
        $visibleFiles = array_values(array_diff($files, ['.', '..']));

        return [
            'run' => $counter,
            'readback' => (string) file_get_contents($messageFile),
            'files' => $visibleFiles,
        ];
    }

    private static function precisionReport(): array
    {
        $big = std::bigInt('123456789012345678901234567890');
        $bigResult = ($big * std::bigInt(1000000) + std::bigInt(42))->toString();

        $price = std::decimal('199.95');
        $taxRate = std::decimal('0.0825');
        $decimalResult = ($price * (std::decimal(1) + $taxRate))->toString();

        $pi = std::bigFloat('3.141592653589793238462643383279502884197');
        $radius = std::bigFloat(12);
        $bigFloatResult = ($pi * $radius * $radius)->toString();

        return [
            'bigint' => $bigResult,
            'decimal' => $decimalResult,
            'bigfloat' => $bigFloatResult,
        ];
    }
}
