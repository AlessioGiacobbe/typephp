<?php

namespace PhpAot\Tests;

use PHPUnit\Framework\TestCase;
use PhpAot\Php\Platform\PlatformFactory;
use PhpAot\Php\Backend\CompilerFactory;
use PhpAot\Php\Platform\Windows;
use PhpAot\Php\Platform\Linux;
use PhpAot\Php\Platform\Macos;

class FactoryTest extends TestCase
{
    /**
     * 测试 PlatformFactory 自动检测
     */
    public function testPlatformFactoryAutoDetect(): void
    {
        $platform = PlatformFactory::create();
        
        $this->assertNotNull($platform);
        $this->assertTrue($platform->isCurrent());
    }

    /**
     * 测试 PlatformFactory 平台判断
     */
    public function testPlatformFactoryPlatformChecks(): void
    {
        // 至少有一个平台判断返回 true
        $isAnyPlatform = PlatformFactory::isWindows() || 
                        PlatformFactory::isLinux() || 
                        PlatformFactory::isMacos();
        
        $this->assertTrue($isAnyPlatform);
    }

    /**
     * 测试 PlatformFactory 获取平台名称
     */
    public function testPlatformFactoryGetName(): void
    {
        $name = PlatformFactory::getCurrentPlatformName();
        
        $this->assertNotEmpty($name);
        $this->assertIsString($name);
    }

    /**
     * 测试 CompilerFactory 自动创建
     */
    public function testCompilerFactoryAutoCreate(): void
    {
        $platform = PlatformFactory::create();
        $compiler = CompilerFactory::create($platform);
        
        $this->assertNotNull($compiler);
        $this->assertEquals($platform, $compiler->getPlatform());
    }

    /**
     * 测试 CompilerFactory 按名称创建 - MSVC
     */
    public function testCompilerFactoryCreateMsvc(): void
    {
        $platform = new Windows();
        $compiler = CompilerFactory::createByName('msvc', $platform);
        
        $this->assertEquals('MSVC', $compiler->getName());
    }

    /**
     * 测试 CompilerFactory 按名称创建 - GCC
     */
    public function testCompilerFactoryCreateGcc(): void
    {
        $platform = new Linux();
        $compiler = CompilerFactory::createByName('gcc', $platform);
        
        $this->assertEquals('GCC', $compiler->getName());
    }

    /**
     * 测试 CompilerFactory 按名称创建 - Clang
     */
    public function testCompilerFactoryCreateClang(): void
    {
        $platform = new Linux();
        $compiler = CompilerFactory::createByName('clang', $platform);
        
        $this->assertEquals('Clang', $compiler->getName());
    }

    /**
     * 测试 CompilerFactory 不支持的编译器
     */
    public function testCompilerFactoryUnsupportedCompiler(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported compiler');
        
        $platform = new Linux();
        CompilerFactory::createByName('unsupported', $platform);
    }

    /**
     * 测试 CompilerFactory 自动检测
     */
    public function testCompilerFactoryAutoDetect(): void
    {
        $result = CompilerFactory::autoDetect();
        
        $this->assertArrayHasKey('platform', $result);
        $this->assertArrayHasKey('compiler', $result);
        $this->assertNotNull($result['platform']);
        $this->assertNotNull($result['compiler']);
    }

    /**
     * 测试 CompilerFactory 自动检测指定编译器
     */
    public function testCompilerFactoryAutoDetectWithCompiler(): void
    {
        $result = CompilerFactory::autoDetect('gcc');
        
        $this->assertNotNull($result['platform']);
        $this->assertEquals('GCC', $result['compiler']->getName());
    }

    /**
     * 测试平台与编译器匹配 - Windows + MSVC
     */
    public function testPlatformCompilerMatchWindowsMsvc(): void
    {
        $platform = new Windows();
        $compiler = CompilerFactory::create($platform);
        
        $this->assertEquals('MSVC', $compiler->getName());
    }

    /**
     * 测试平台与编译器匹配 - Linux + GCC
     */
    public function testPlatformCompilerMatchLinuxGcc(): void
    {
        $platform = new Linux();
        $compiler = CompilerFactory::create($platform);
        
        $this->assertEquals('GCC', $compiler->getName());
    }

    /**
     * 测试平台与编译器匹配 - macOS + Clang
     */
    public function testPlatformCompilerMatchMacosClang(): void
    {
        $platform = new Macos();
        $compiler = CompilerFactory::create($platform);
        
        $this->assertEquals('Clang', $compiler->getName());
    }

    /**
     * 测试编译器可以获取平台实例
     */
    public function testCompilerGetPlatform(): void
    {
        $platform = new Windows();
        $compiler = CompilerFactory::create($platform);
        
        $retrievedPlatform = $compiler->getPlatform();
        
        $this->assertSame($platform, $retrievedPlatform);
    }
}
