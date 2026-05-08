<?php

namespace PhpAot\Tests;

use PHPUnit\Framework\TestCase;
use PhpAot\Php\CompilerTest;

class CompilerBaseAdapterTest extends TestCase
{
    private string $testDir;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->testDir = sys_get_temp_dir() . '/compiler_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
    }
    
    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->testDir)) {
            // 递归删除测试目录
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
    
    /**
     * 测试 CompilerBase 初始化新架构
     */
    public function testCompilerBaseInitializesNewArchitecture(): void
    {
        $compiler = CompilerTest::create($this->testDir);
        
        // 使用反射检查新架构是否已初始化
        $reflection = new \ReflectionClass($compiler);
        
        $platformProp = $reflection->getProperty('platform');
        $platformProp->setAccessible(true);
        $platform = $platformProp->getValue($compiler);
        
        $backendProp = $reflection->getProperty('compilerBackend');
        $backendProp->setAccessible(true);
        $backend = $backendProp->getValue($compiler);
        
        // 新架构应该被初始化（除非检测失败）
        $this->assertNotNull($platform, 'Platform should be initialized');
        $this->assertNotNull($backend, 'Backend should be initialized');
    }
    
    /**
     * 测试 parseIncludes 使用新架构
     */
    public function testParseIncludesUsesNewArchitecture(): void
    {
        $compiler = CompilerTest::create($this->testDir);
        
        // 使用反射调用 protected 方法
        $reflection = new \ReflectionClass($compiler);
        $method = $reflection->getMethod('parseIncludes');
        $method->setAccessible(true);
        $includes = $method->invoke($compiler);
        
        // 应该返回非空字符串
        $this->assertNotEmpty($includes);
        $this->assertIsString($includes);
        
        // Windows 下应该包含 /I，Unix 下应该包含 -I
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->assertStringContainsString('/I', $includes);
        } else {
            $this->assertStringContainsString('-I', $includes);
        }
    }
    
    /**
     * 测试 parseLdflags 使用新架构
     */
    public function testParseLdflagsUsesNewArchitecture(): void
    {
        $compiler = CompilerTest::create($this->testDir);
        
        // 使用反射调用 protected 方法
        $reflection = new \ReflectionClass($compiler);
        $method = $reflection->getMethod('parseLdflags');
        $method->setAccessible(true);
        $ldflags = $method->invoke($compiler);
        
        // 应该返回非空字符串
        $this->assertNotEmpty($ldflags);
        $this->assertIsString($ldflags);
        
        // Windows 下应该包含 /LIBPATH，Unix 下应该包含 -L
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $this->assertStringContainsString('/LIBPATH', $ldflags);
        } else {
            $this->assertStringContainsString('-L', $ldflags);
        }
    }
    
    /**
     * 测试 parseLibs 使用新架构
     */
    public function testParseLibsUsesNewArchitecture(): void
    {
        $compiler = CompilerTest::create($this->testDir);
        
        // 使用反射调用 protected 方法
        $reflection = new \ReflectionClass($compiler);
        $method = $reflection->getMethod('parseLibs');
        $method->setAccessible(true);
        
        // 可能会抛出异常（如果没有找到库），这是正常的
        try {
            $libs = $method->invoke($compiler);
            
            // 如果成功，应该返回非空字符串
            $this->assertNotEmpty($libs);
            $this->assertIsString($libs);
            
            // 应该包含 phpx 库
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                $this->assertStringContainsString('phpx', $libs);
            } else {
                $this->assertStringContainsString('-lphpx', $libs);
            }
        } catch (\Exception $e) {
            // 如果抛出异常，验证是预期的库未找到错误
            $this->assertStringContainsString('libphpx', $e->getMessage());
        }
    }
    
    /**
     * 测试平台检测方法一致性
     */
    public function testPlatformDetectionConsistency(): void
    {
        $compiler = CompilerTest::create($this->testDir);
        
        $reflection = new \ReflectionClass($compiler);
        
        // 获取旧的 isWindows 方法
        $isWindowsMethod = $reflection->getMethod('isWindows');
        $isWindowsMethod->setAccessible(true);
        $isWindowsOld = $isWindowsMethod->invoke($compiler);
        
        // 获取新的 platform 属性
        $platformProp = $reflection->getProperty('platform');
        $platformProp->setAccessible(true);
        $platform = $platformProp->getValue($compiler);
        
        if ($platform !== null) {
            $isWindowsNew = $platform instanceof \PhpAot\Php\Platform\Windows;
            
            // 新旧方法应该一致
            $this->assertEquals($isWindowsOld, $isWindowsNew, 
                'Old and new platform detection should be consistent');
        }
    }
    
    /**
     * 测试向后兼容性 - 旧方法仍然可用
     */
    public function testBackwardCompatibility(): void
    {
        $compiler = CompilerTest::create($this->testDir);
        
        // 旧的方法应该仍然可以调用
        $this->assertIsBool($compiler->isWindows());
        
        // 使用反射调用 protected 方法
        $reflection = new \ReflectionClass($compiler);
        $method = $reflection->getMethod('parseIncludes');
        $method->setAccessible(true);
        $includes = $method->invoke($compiler);
        
        $this->assertIsString($includes);
    }
}
