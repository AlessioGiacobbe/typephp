<?php

namespace TypePhp\Tests;

use PHPUnit\Framework\TestCase;
use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;

class WasiUnsupportedSyntaxTest extends TestCase
{
    /** @dataProvider unsupportedConversionSyntaxProvider */
    public function testUnsupportedSyntaxFailsDuringWasiConversion(string $file, string $message): void
    {
        $compiler = CompilerTest::create(ROOT_PATH);
        (new \ReflectionClass($compiler))->getProperty('targetPlatform')->setValue($compiler, 'wasm32-wasi');
        $source = __DIR__ . '/../code/' . $file;
        $compiler->addFiles([$source]);
        $compiler->prepareFile($source);

        $this->expectException(TestError::class);
        $this->expectExceptionMessage($message);
        $compiler->convertFile($source);
    }

    public static function unsupportedConversionSyntaxProvider(): array
    {
        return [
            'backtick shell execution' => [
                'wasi-backtick.php',
                'Backtick shell execution is not supported by the WASI target',
            ],
            'generator closure' => [
                'wasi-generator-closure.php',
                'Fiber and Generator are not supported by the WASI target',
            ],
            'generator arrow function' => [
                'wasi-generator-arrow.php',
                'Fiber and Generator are not supported by the WASI target',
            ],
        ];
    }
}
