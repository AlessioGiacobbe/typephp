<?php

namespace TypePhp\Tests;

use PHPUnit\Framework\TestCase;
use PhpParser\Node;
use TypePhp\CompilerTest;
use TypePhp\Diagnostics\DiagnosticReporter;
use TypePhp\Exception\TestError;

/**
 * Constant integer arithmetic overflow emits compile-time warnings and folds
 * to the PHP float result in non-native mode.
 */
class ConstantArithmeticOverflowTest extends TestCase
{
    public function testOverflowingConstantArithmeticEmitsWarning(): void
    {
        $reporter = $this->compileWithReporter();

        $overflowWarnings = array_values(array_filter(
            $reporter->warnings,
            fn (string $message): bool => str_contains($message, 'Constant integer arithmetic overflows int64')
        ));
        $this->assertCount(2, $overflowWarnings);
        $this->assertStringContainsString('9223372036854775807 + 1', $overflowWarnings[0]);
        $this->assertStringContainsString('folding to PHP float result', $overflowWarnings[0]);
    }

    public function testNonOverflowingConstantArithmeticDoesNotWarn(): void
    {
        $reporter = $this->compileWithReporter();

        foreach ($reporter->warnings as $message) {
            $this->assertStringNotContainsString('1 + 2', $message);
        }
    }

    /**
     * @return object{warnings: list<string>}
     */
    private function compileWithReporter(): object
    {
        global $translator;
        $compiler = CompilerTest::create(ROOT_PATH);
        $translator = $compiler;
        $reporter = new class implements DiagnosticReporter {
            /** @var list<string> */
            public array $warnings = [];

            public function fatal(string $message): never
            {
                throw new TestError($message);
            }

            public function warning(Node $node, string $file, string $message): void
            {
                $this->warnings[] = $message;
            }
        };
        $compiler->setDiagnosticReporter($reporter);

        $testFile = __DIR__ . '/../code/constant-overflow-warning.php';
        $compiler->addFiles([$testFile]);
        $compiler->prepareFile($testFile);
        $compiler->convertFile($testFile);

        return $reporter;
    }
}
