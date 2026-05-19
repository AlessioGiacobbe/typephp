<?php

namespace PhpAot\Tests;

use PHPUnit\Framework\TestCase;
use PhpAot\Php\CompilerTest;
use PhpAot\Php\CompilerBase;

class CompilerBaseApiTest extends TestCase
{
    private string $testDir;
    private CompilerTest $compiler;
    private \ReflectionClass $ref;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/compiler_api_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
        $this->compiler = CompilerTest::create($this->testDir);
        $this->ref = new \ReflectionClass($this->compiler);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        // Recursively remove the test directory (compiler creates build/ subdir)
        $this->removeDirectory($this->testDir);
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

    private function getPropertyValue(string $name): mixed
    {
        $prop = $this->ref->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($this->compiler);
    }

    private function setPropertyValue(string $name, mixed $value): void
    {
        $prop = $this->ref->getProperty($name);
        $prop->setAccessible(true);
        $prop->setValue($this->compiler, $value);
    }

    private function invokeMethod(string $method, ...$args): mixed
    {
        $m = $this->ref->getMethod($method);
        $m->setAccessible(true);
        return $m->invoke($this->compiler, ...$args);
    }

    // ========================================================================
    // getTypeFromZendType
    // ========================================================================

    public function testGetTypeFromZendTypeKnown(): void
    {
        $this->assertEquals(CompilerBase::TYPE_INT, $this->compiler->getTypeFromZendType('int'));
        $this->assertEquals(CompilerBase::TYPE_FLOAT, $this->compiler->getTypeFromZendType('float'));
        $this->assertEquals(CompilerBase::TYPE_BOOL, $this->compiler->getTypeFromZendType('bool'));
        $this->assertEquals(CompilerBase::TYPE_BOOL, $this->compiler->getTypeFromZendType('true'));
        $this->assertEquals(CompilerBase::TYPE_BOOL, $this->compiler->getTypeFromZendType('false'));
        $this->assertEquals(CompilerBase::TYPE_VOID, $this->compiler->getTypeFromZendType('void'));
        $this->assertEquals(CompilerBase::TYPE_VOID, $this->compiler->getTypeFromZendType('never'));
        $this->assertEquals(CompilerBase::TYPE_STR, $this->compiler->getTypeFromZendType('string'));
        $this->assertEquals(CompilerBase::TYPE_ARRAY, $this->compiler->getTypeFromZendType('array'));
        $this->assertEquals(CompilerBase::TYPE_OBJECT, $this->compiler->getTypeFromZendType('object'));
        $this->assertEquals(CompilerBase::TYPE_VAR, $this->compiler->getTypeFromZendType('mixed'));
        $this->assertEquals(CompilerBase::TYPE_VAR, $this->compiler->getTypeFromZendType('null'));
        $this->assertEquals(CompilerBase::TYPE_VAR, $this->compiler->getTypeFromZendType('callable'));
        $this->assertEquals(CompilerBase::TYPE_VAR, $this->compiler->getTypeFromZendType('iterable'));
        $this->assertEquals(CompilerBase::TYPE_VAR, $this->compiler->getTypeFromZendType('UnsafePtr'));
    }

    public function testGetTypeFromZendTypeUnknown(): void
    {
        $this->assertEquals(CompilerBase::TYPE_VAR, $this->compiler->getTypeFromZendType('unknown_type'));
        $this->assertEquals(CompilerBase::TYPE_VAR, $this->compiler->getTypeFromZendType('SomeClass'));
    }

    // ========================================================================
    // genTmpVarName
    // ========================================================================

    public function testGenTmpVarName(): void
    {
        // context must be initialized before genTmpVarName can be used
        $this->invokeMethod('resetFunction');

        $name1 = $this->compiler->genTmpVarName();
        $name2 = $this->compiler->genTmpVarName();
        $name3 = $this->compiler->genTmpVarName();

        $this->assertStringStartsWith('tmp_var_', $name1);
        $this->assertStringStartsWith('tmp_var_', $name2);
        $this->assertStringStartsWith('tmp_var_', $name3);

        // Must be sequential and unique
        $this->assertNotEquals($name1, $name2);
        $this->assertNotEquals($name2, $name3);
        $this->assertNotEquals($name1, $name3);
    }

    // ========================================================================
    // genAnonClassName
    // ========================================================================

    public function testGenAnonClassName(): void
    {
        $name1 = $this->compiler->genAnonClassName();
        $name2 = $this->compiler->genAnonClassName();

        $this->assertStringStartsWith(CompilerBase::ANON_CLASS, $name1);
        $this->assertStringStartsWith(CompilerBase::ANON_CLASS, $name2);
        $this->assertNotEquals($name1, $name2);
    }

    // ========================================================================
    // getIncludeDir / getBuildDir
    // ========================================================================

    public function testGetBuildDir(): void
    {
        $buildDir = $this->compiler->getBuildDir();
        $this->assertStringEndsWith('/build', $buildDir);
        $this->assertStringStartsWith($this->testDir, $buildDir);
    }

    public function testGetIncludeDir(): void
    {
        $includeDir = $this->compiler->getIncludeDir();
        $buildDir = $this->compiler->getBuildDir();
        $this->assertEquals($buildDir . '/include', $includeDir);
    }

    // ========================================================================
    // isWindows / isLinux / isMacos
    // ========================================================================

    public function testPlatformDetectionMethods(): void
    {
        $isWin = $this->compiler->isWindows();
        $isLin = $this->compiler->isLinux();
        $isMac = $this->compiler->isMacos();

        // Exactly one platform must be true
        $sum = ($isWin ? 1 : 0) + ($isLin ? 1 : 0) + ($isMac ? 1 : 0);
        $this->assertEquals(1, $sum, 'Exactly one platform must be detected');

        // All return bool
        $this->assertIsBool($isWin);
        $this->assertIsBool($isLin);
        $this->assertIsBool($isMac);
    }

    // ========================================================================
    // isScalarInt - public method
    // ========================================================================

    public function testIsScalarIntTrue(): void
    {
        $this->assertTrue($this->compiler->isScalarInt(new \PhpParser\Node\Scalar\LNumber(42)));
    }

    public function testIsScalarIntFalse(): void
    {
        $this->assertFalse($this->compiler->isScalarInt(new \PhpParser\Node\Expr\Variable('a')));
    }

    // ========================================================================
    // getNamespacedClassName - fully qualified
    // ========================================================================

    public function testGetNamespacedClassNameFullyQualified(): void
    {
        $this->assertEquals(
            'App\\Entity\\User',
            $this->compiler->getNamespacedClassName('\\App\\Entity\\User')
        );
    }

    // ========================================================================
    // getNamespacedClassName - with use alias
    // ========================================================================

    public function testGetNamespacedClassNameWithUseAlias(): void
    {
        $this->setPropertyValue('useAliases', ['User' => 'App\\Entity\\User']);
        $this->assertEquals(
            'App\\Entity\\User',
            $this->compiler->getNamespacedClassName('User')
        );
    }

    public function testGetNamespacedClassNameWithUseAliasSubNamespace(): void
    {
        $this->setPropertyValue('useAliases', ['Entity' => 'App\\Entity']);
        $this->assertEquals(
            'App\\Entity\\User',
            $this->compiler->getNamespacedClassName('Entity\\User')
        );
    }

    // ========================================================================
    // getNamespacedClassName - with use namespace (partial match)
    // ========================================================================

    public function testGetNamespacedClassNameWithUseNamespace(): void
    {
        $this->setPropertyValue('useNamespaces', ['App\\Entity']);
        // The last segment of 'App\Entity' is 'Entity', matching input 'Entity'
        $this->assertEquals(
            'App\\Entity',
            $this->compiler->getNamespacedClassName('Entity')
        );
    }

    public function testGetNamespacedClassNameWithUseNamespaceSub(): void
    {
        $this->setPropertyValue('useNamespaces', ['App\\Entity']);
        // 'Entity\User' - first part 'Entity' matches the last part of 'App\Entity'
        $this->assertEquals(
            'App\\Entity\\User',
            $this->compiler->getNamespacedClassName('Entity\\User')
        );
    }

    // ========================================================================
    // getNamespacedClassName - with current namespace
    // ========================================================================

    public function testGetNamespacedClassNameWithCurrentNamespace(): void
    {
        $this->setPropertyValue('namespace', 'App\\Service');
        // No matching alias or use namespace
        $this->setPropertyValue('useAliases', []);
        $this->setPropertyValue('useNamespaces', []);
        $this->assertEquals(
            'App\\Service\\MyClass',
            $this->compiler->getNamespacedClassName('MyClass')
        );
    }

    public function testGetNamespacedClassNameNoNamespace(): void
    {
        $this->setPropertyValue('namespace', '');
        $this->setPropertyValue('useAliases', []);
        $this->setPropertyValue('useNamespaces', []);
        $this->assertEquals(
            'MyClass',
            $this->compiler->getNamespacedClassName('MyClass')
        );
    }

    // ========================================================================
    // getNamespacedClassName - alias takes priority over use namespace
    // ========================================================================

    public function testGetNamespacedClassNameAliasPriority(): void
    {
        $this->setPropertyValue('useAliases', ['User' => 'App\\Models\\User']);
        $this->setPropertyValue('useNamespaces', ['App\\Controllers']);
        // Alias should be checked first
        $this->assertEquals(
            'App\\Models\\User',
            $this->compiler->getNamespacedClassName('User')
        );
    }

    // ========================================================================
    // getNamespacedFuncName
    // ========================================================================

    public function testGetNamespacedFuncNameFullyQualified(): void
    {
        $this->assertEquals(
            'App\\Lib\\helper_func',
            $this->compiler->getNamespacedFuncName('\\App\\Lib\\helper_func')
        );
    }

    public function testGetNamespacedFuncNameWithUseFunction(): void
    {
        $this->setPropertyValue('useFunctions', [
            'helper_func' => 'App\\Lib',
        ]);
        $this->assertEquals(
            'App\\Lib\\helper_func',
            $this->compiler->getNamespacedFuncName('helper_func')
        );
    }

    public function testGetNamespacedFuncNameNoNamespace(): void
    {
        $this->setPropertyValue('useFunctions', []);
        $this->assertEquals(
            'helper_func',
            $this->compiler->getNamespacedFuncName('helper_func')
        );
    }

    // ========================================================================
    // getNamespacedFuncName - not in useFunctions returns bare name
    // ========================================================================

    public function testGetNamespacedFuncNameNotInUseFunctions(): void
    {
        $this->setPropertyValue('useFunctions', ['other' => 'Some\\Ns']);
        $this->assertEquals(
            'my_func',
            $this->compiler->getNamespacedFuncName('my_func')
        );
    }

    // ========================================================================
    // getPhpDir
    // ========================================================================

    public function testGetPhpDir(): void
    {
        $phpDir = $this->compiler->getPhpDir();
        $this->assertIsString($phpDir);
        $this->assertNotEmpty($phpDir);
    }
}
