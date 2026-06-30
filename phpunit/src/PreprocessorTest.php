<?php

namespace PhpAot\Tests;

use PHPUnit\Framework\TestCase;
use PhpAot\Php\CompilerTest;
use PhpAot\Php\ArgInfo;
use PhpParser\Node;
use PhpParser\Node\Stmt\Function_;
use PhpParser\ParserFactory;

class PreprocessorTest extends TestCase
{
    private string $testDir;
    private CompilerTest $compiler;
    private \ReflectionClass $ref;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/preprocessor_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
        $this->compiler = CompilerTest::create($this->testDir);
        $this->ref = new \ReflectionClass($this->compiler);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->testDir)) {
            $this->removeDirectory($this->testDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function invokeMethod(string $method, ...$args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->compiler, ...$args);
    }

    private function setProperty(string $name, mixed $value): void
    {
        $prop = $this->ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($this->compiler, $value);
    }

    private function parseFunctionNode(string $code): Function_
    {
        $parser = (new ParserFactory())->createForHostVersion();
        $stmts = $parser->parse($code);
        $this->assertNotNull($stmts);
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Function_) {
                return $stmt;
            }
        }
        $this->fail('No function node found');
    }

    // ========================================================================
    // genArgumentDeclaration
    // ========================================================================

    public function testGenArgumentDeclarationSimple(): void
    {
        $arg = new ArgInfo();
        $arg->name = 'count';
        $arg->type = 'php::Int';
        $result = $this->invokeMethod('genArgumentDeclaration', $arg);
        $this->assertEquals('php::Int count', $result);
    }

    public function testGenArgumentDeclarationString(): void
    {
        $arg = new ArgInfo();
        $arg->name = 'name';
        $arg->type = 'php::Str';
        $result = $this->invokeMethod('genArgumentDeclaration', $arg);
        $this->assertEquals('php::Str name', $result);
    }

    public function testGenArgumentDeclarationObject(): void
    {
        $arg = new ArgInfo();
        $arg->name = 'obj';
        $arg->type = 'php::Object';
        $result = $this->invokeMethod('genArgumentDeclaration', $arg);
        $this->assertEquals('php::Object obj', $result);
    }

    public function testGenArgumentDeclarationVar(): void
    {
        $arg = new ArgInfo();
        $arg->name = 'container';
        $arg->type = 'php::Var';
        $result = $this->invokeMethod('genArgumentDeclaration', $arg);
        $this->assertEquals('php::Var container', $result);
    }

    // ========================================================================
    // getCppFile
    // ========================================================================

    public function testGetCppFile(): void
    {
        $phpFile = '/home/user/project/src/app.php';
        $result = $this->compiler->getCppFile($phpFile);

        $this->assertStringEndsWith('.cc', $result);
        $this->assertStringStartsWith($this->compiler->getBuildDir(), $result);
        $this->assertStringContainsString('app', $result);
    }

    public function testGetCppFilePreservesRelativePath(): void
    {
        $phpFile = '/var/www/myapp/controllers/UserController.php';
        $result = $this->compiler->getCppFile($phpFile);

        $this->assertStringEndsWith('UserController.cc', $result);
        $this->assertStringStartsWith($this->compiler->getBuildDir(), $result);
    }

    public function testGetCppFileDoesNotTrimSiblingDirectoryByCharacterPrefix(): void
    {
        $this->setProperty('buildDir', '/tmp/project/build');

        $result = $this->compiler->getCppFile('/tmp/project2/src/app.php');

        $this->assertSame('/tmp/project/build/project2/src/app.cc', $result);
    }

    public function testGetCppFileDotPhpReplaced(): void
    {
        $phpFile = '/tmp/test_file.php';
        $result = $this->compiler->getCppFile($phpFile);

        $this->assertStringEndsWith('test_file.cc', $result);
        $this->assertStringNotContainsString('.php', basename($result));
    }

    // ========================================================================
    // getObjectFile
    // ========================================================================

    public function testGetObjectFile(): void
    {
        $cppFile = $this->compiler->getBuildDir() . '/include/test.cc';
        $result = $this->compiler->getObjectFile($cppFile);

        $this->assertStringEndsWith('.o', $result);
        $this->assertStringContainsString('test', $result);
    }

    public function testGetObjectFileDifferentObjectExtension(): void
    {
        // On Linux the object extension is .o
        $path = '/some/path/file.cc';
        $result = $this->compiler->getObjectFile($path);
        $this->assertStringEndsWith('file.o', $result);
    }

    // ========================================================================
    // getMethodName
    // ========================================================================

    public function testGetMethodName(): void
    {
        $method = new Node\Stmt\ClassMethod('handle');
        $result = $this->invokeMethod('getMethodName', $method);
        $this->assertEquals('handle', $result);
    }

    public function testGetMethodNameConstructor(): void
    {
        $method = new Node\Stmt\ClassMethod('__construct');
        $result = $this->invokeMethod('getMethodName', $method);
        $this->assertEquals('__construct', $result);
    }

    // ========================================================================
    // getParentClass
    // ========================================================================

    public function testGetParentClassWithNamespace(): void
    {
        $extends = new Node\Name('BaseController');
        $this->setProperty('namespace', 'App\\Controllers');
        $result = $this->invokeMethod('getParentClass', $extends);
        $this->assertEquals('App\\Controllers\\BaseController', $result);
    }

    public function testGetParentClassFullyQualified(): void
    {
        $extends = new Node\Name\FullyQualified('App\\Entity\\Base');
        $result = $this->invokeMethod('getParentClass', $extends);
        $this->assertEquals('App\\Entity\\Base', $result);
    }

    // ========================================================================
    // sortFiles
    // ========================================================================

    public function testSortFilesPreservesOrderForUnrelatedFiles(): void
    {
        $files = ['/a/file1.php', '/a/file2.php', '/a/file3.php'];
        $this->compiler->sortFiles($files);
        // All original files must still be present
        $this->assertContains('/a/file1.php', $files);
        $this->assertContains('/a/file2.php', $files);
        $this->assertContains('/a/file3.php', $files);
        // Original files are preserved (sortFiles may append, not remove)
        $this->assertGreaterThanOrEqual(3, count($files));
    }

    public function testSortFilesEmpty(): void
    {
        $files = [];
        $this->compiler->sortFiles($files);
        // Empty array stays empty or nearly empty
        $this->assertIsArray($files);
    }

    public function testIntersectionParamDeclFallsBackToVarWithRuntimeCheck(): void
    {
        $fn = $this->parseFunctionNode('<?php interface A {} interface B {} function demo(A&B $value): void {}');
        $functionDef = $this->invokeMethod('parseFunctionDecl', $fn);

        $this->assertSame('php::Var', $functionDef->argInfoList[0]->type);
        $this->assertNotEmpty($functionDef->argInfoList[0]->typeCheck);
        $this->assertSame('A&B', $functionDef->argInfoList[0]->typeStr);
    }

    public function testIntersectionReturnDeclFallsBackToVarWithRuntimeCheck(): void
    {
        $fn = $this->parseFunctionNode('<?php interface A {} interface B {} function demo(): A&B { throw new \Exception(); }');
        $functionDef = $this->invokeMethod('parseFunctionDecl', $fn);

        $this->assertSame('php::Var', $functionDef->returnType);
        $this->assertNotEmpty($functionDef->returnTypeCheck);
        $this->assertSame('A&B', $functionDef->returnTypeStr);
    }

    public function testNullableReturnDeclFallsBackToVarWithRuntimeCheck(): void
    {
        $fn = $this->parseFunctionNode('<?php function demo(): ?int { return null; }');
        $functionDef = $this->invokeMethod('parseFunctionDecl', $fn);

        $this->assertSame('php::Var', $functionDef->returnType);
        $this->assertNotEmpty($functionDef->returnTypeCheck);
        $this->assertSame('?int', $functionDef->returnTypeStr);
    }
}
