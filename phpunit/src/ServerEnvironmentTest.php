<?php

namespace TypePhp\Tests;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use TypePhp\CompilerBase;
use TypePhp\CompilerTest;
use TypePhp\Translator;

class ServerEnvironmentTest extends TestCase
{
    private string $testDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/server_environment_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->removeDirectory($this->testDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (array_diff(scandir($dir), ['.', '..']) as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testGeneratedServerEnvironmentMatchesCliAndEscapesScriptPath(): void
    {
        $compiler = CompilerTest::create($this->testDir);
        $method = new ReflectionMethod(Translator::class, 'registerServerEnvironment');
        $code = $method->invoke($compiler, 'C:\\project\\"quoted"\\main.php');

        $this->assertStringContainsString('php::Var &_SERVER = _global_var__SERVER;', $code);
        $this->assertStringContainsString('php::Str php_self = "PHP_SELF";', $code);
        $this->assertStringContainsString('php::Str script_name = "SCRIPT_NAME";', $code);
        $this->assertStringContainsString('php::Str script_filename = "SCRIPT_FILENAME";', $code);
        $this->assertStringContainsString('php::Str path_translated = "PATH_TRANSLATED";', $code);
        $this->assertStringContainsString('php::Str document_root = "DOCUMENT_ROOT";', $code);
        $this->assertStringContainsString(
            'php::Str value = "' . $compiler->escapeString('C:\\project\\"quoted"\\main.php') . '";',
            $code
        );
        $this->assertStringContainsString('_SERVER.item(path_translated, true) = value;', $code);
        $this->assertStringContainsString('_SERVER.item(document_root, true) = "";', $code);
    }

    public function testServerGlobalIsForcedOnlyForBinaryBuilds(): void
    {
        $binaryCompiler = CompilerTest::create($this->testDir);
        $binaryFile = $this->testDir . '/binary.h';
        $binaryCompiler->genDataDeclarations($binaryFile);
        $this->assertStringContainsString('_global_var__SERVER', file_get_contents($binaryFile));

        $extensionCompiler = CompilerTest::create($this->testDir);
        $extensionCompiler->setBuildMode(CompilerBase::BUILD_MODE_EXT);
        $extensionFile = $this->testDir . '/extension.h';
        $extensionCompiler->genDataDeclarations($extensionFile);
        $this->assertStringNotContainsString('_global_var__SERVER', file_get_contents($extensionFile));
    }
}
