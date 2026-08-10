<?php

namespace TypePhpTest\PythonTools;

use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use TypePhp\PythonTools\Command;

final class PythonToolsCommandTest extends TestCase
{
    #[RequiresPhpExtension('phpy')]
    public function testCustomOutputDirectoryPreservesExistingPyObjectHelper(): void
    {
        $root = sys_get_temp_dir() . '/typephp-python-tools-' . bin2hex(random_bytes(6));
        $output = $root . '/stubs';
        self::assertTrue(mkdir($output, 0777, true));
        self::assertNotFalse(file_put_contents($output . '/PyObject.php', 'keep-me'));

        try {
            $status = Command::execute(
                ['tpc', Command::GENERATE_HELPER, 'math', '--output-dir', 'stubs'],
                $root,
            );

            self::assertSame(0, $status);
            self::assertFileExists($output . '/python/math.php');
            self::assertFileExists($output . '/python.php');
            $builtins = file_get_contents($output . '/python.php');
            self::assertIsString($builtins);
            self::assertStringContainsString('namespace python;', $builtins);
            self::assertStringContainsString('function tuple(', $builtins);
            self::assertStringContainsString(
                '// Omitted Python callable not representable as a PHP function: print',
                $builtins,
            );
            self::assertSame('keep-me', file_get_contents($output . '/PyObject.php'));
        } finally {
            @unlink($output . '/python/math.php');
            @rmdir($output . '/python');
            @unlink($output . '/python.php');
            @unlink($output . '/PyObject.php');
            @rmdir($output);
            @rmdir($root);
        }
    }
}
