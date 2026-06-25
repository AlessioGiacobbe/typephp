<?php

namespace PhpAot\Tests\Backend;

use PHPUnit\Framework\TestCase;
use PhpAot\Php\Platform\Windows;
use PhpAot\Php\Platform\Linux;
use PhpAot\Php\Platform\Macos;
use PhpAot\Php\Backend\Msvc;
use PhpAot\Php\Backend\Gcc;
use PhpAot\Php\Backend\Clang;
use PhpAot\Php\Backend\CompilerFactory;

class BackendTest extends TestCase
{
    /**
     * 测试 MSVC 编译器基本信息
     */
    public function testMsvcBasic(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $this->assertEquals('MSVC', $compiler->getName());
        $this->assertEquals('cl', $compiler->getCompilerCommand());
        $this->assertEquals('link', $compiler->getLinkerCommand());
    }

    /**
     * 测试 MSVC 编译单个文件
     */
    public function testMsvcCompileFile(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $cmd = $compiler->compileFile(
            'test.cpp',
            'test.obj',
            ['C:\PHP\include'],
            ['ZEND_WIN32']
        );
        
        $this->assertStringContainsString('cl', $cmd);
        $this->assertStringContainsString('/c', $cmd);
        $this->assertStringContainsString('test.cpp', $cmd);
        $this->assertStringContainsString('/Fo', $cmd);
        $this->assertStringContainsString('test.obj', $cmd);
        $this->assertStringContainsString('/I', $cmd);
        $this->assertStringContainsString('/DZEND_WIN32', $cmd);
    }

    /**
     * 测试 MSVC 链接对象文件
     */
    public function testMsvcLinkObjects(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);

        $cmd = $compiler->linkObjects(
            ['test.obj'],
            'output.exe',
            ['C:\PHP\lib'],
            ['php8.lib']
        );

        $this->assertStringContainsString('link', $cmd);
        $this->assertStringContainsString('@', $cmd);
        $this->assertStringContainsString('.rsp', $cmd);
        $this->assertStringContainsString('/OUT:', $cmd);
        $this->assertStringContainsString('output.exe', $cmd);
        $this->assertStringContainsString('/LIBPATH:', $cmd);
    }

    /**
     * 测试 MSVC 完整编译命令
     */
    public function testMsvcBuildCompileCommand(): void
    {
        $platform = new Windows([], true); // ZTS mode
        $compiler = new Msvc($platform);
        
        $cmd = $compiler->buildCompileCommand(
            'test.cpp',
            'test.obj',
            [
                'optimize' => 2,
                'cpp_std' => 'c++17',
            ]
        );
        
        $this->assertStringContainsString('cl', $cmd);
        $this->assertStringContainsString('/c', $cmd);
        $this->assertStringContainsString('/DZEND_WIN32', $cmd);
        $this->assertStringContainsString('/DPHP_WIN32', $cmd);
        $this->assertStringContainsString('/DZTS', $cmd); // ZTS enabled
        $this->assertStringContainsString('/O2', $cmd);
        $this->assertStringContainsString('/W3', $cmd);
        $this->assertStringContainsString('/std:c++17', $cmd);
        $this->assertStringContainsString('/EHsc', $cmd);
        $this->assertStringContainsString('/MD', $cmd);
        $this->assertStringContainsString('/nologo', $cmd);
    }

    /**
     * 测试 MSVC 完整链接命令
     */
    public function testMsvcBuildLinkCommand(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $cmd = $compiler->buildLinkCommand(
            ['test.obj'],
            'output.exe',
            [
                'debug' => true,
                'no_console' => false,
            ]
        );
        
        $this->assertStringContainsString('link', $cmd);
        $this->assertStringContainsString('/OUT:', $cmd);
        $this->assertStringContainsString('/DEBUG', $cmd);
        $this->assertStringContainsString('/NODEFAULTLIB:LIBCMT', $cmd);
        $this->assertStringContainsString('/nologo', $cmd);
    }

    /**
     * 测试 MSVC 完整编译选项
     */
    public function testMsvcFullCompileOptions(): void
    {
        $platform = new Windows([], true); // ZTS
        $compiler = new Msvc($platform);
        
        $options = $compiler->buildFullCompileOptions([
            'optimize' => 2,
            'debug' => false,
            'sanitize' => null,
            'cpp_std' => 'c++17',
            'suppressed_warnings' => [
                '4996' => 'deprecated function',
                '4267' => 'size_t conversion',
            ],
        ]);
        
        $this->assertStringContainsString('/DZEND_WIN32', $options);
        $this->assertStringContainsString('/DZTS', $options);
        $this->assertStringContainsString('/O2', $options);
        $this->assertStringContainsString('/W3', $options);
        $this->assertStringContainsString('/wd4996', $options);
        $this->assertStringContainsString('/wd4267', $options);
        $this->assertStringContainsString('/EHsc', $options);
        $this->assertStringContainsString('/std:c++17', $options);
        $this->assertStringContainsString('/MD', $options);
        $this->assertStringContainsString('/nologo', $options);
    }

    /**
     * 测试 MSVC 调试模式编译选项
     */
    public function testMsvcDebugCompileOptions(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $options = $compiler->buildFullCompileOptions([
            'debug' => true,
        ]);
        
        $this->assertStringContainsString('/Od', $options); // 禁用优化
        $this->assertStringContainsString('/Zi', $options); // 生成调试信息
    }

    /**
     * 测试 MSVC 完整链接选项
     */
    public function testMsvcFullLinkOptions(): void
    {
        $platform = new Windows();
        $compiler = new Msvc($platform);
        
        $options = $compiler->buildFullLinkOptions([
            'debug' => true,
            'no_console' => true,
            'shared' => true,
        ]);
        
        $this->assertStringContainsString('/DEBUG', $options);
        $this->assertStringContainsString('/SUBSYSTEM:WINDOWS', $options);
        $this->assertStringContainsString('/ENTRY:mainCRTStartup', $options);
        $this->assertStringContainsString('/NODEFAULTLIB:LIBCMT', $options);
        $this->assertStringContainsString('/DLL', $options);
        $this->assertStringContainsString('/nologo', $options);
    }

    /**
     * 测试 GCC 编译器基本信息
     */
    public function testGccBasic(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        $this->assertEquals('GCC', $compiler->getName());
        $this->assertEquals('g++', $compiler->getCompilerCommand());
        $this->assertEquals('g++', $compiler->getLinkerCommand());
    }

    /**
     * 测试 GCC 编译单个文件
     */
    public function testGccCompileFile(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        $cmd = $compiler->compileFile(
            'test.cpp',
            'test.o',
            ['/usr/include/php'],
            ['ZEND_WIN32']
        );
        
        $this->assertStringContainsString('g++', $cmd);
        $this->assertStringContainsString('-c', $cmd);
        $this->assertStringContainsString('test.cpp', $cmd);
        $this->assertStringContainsString('-o', $cmd);
        $this->assertStringContainsString('test.o', $cmd);
        $this->assertStringContainsString('-I', $cmd);
        $this->assertStringContainsString('-DZEND_WIN32', $cmd);
    }

    public function testGccBuildCompileCommandUsesCustomCompilerAndIncludes(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform, '/opt/toolchain/bin/g++');

        $cmd = $compiler->buildCompileCommand('test.cpp', 'test.o', [
            'include_paths' => ['/usr/include/php'],
            'cpp_std' => 'c++20',
            'cxxflags' => '-fno-rtti',
        ]);

        $this->assertStringStartsWith('/opt/toolchain/bin/g++', $cmd);
        $this->assertStringContainsString('-I' . escapeshellarg('/usr/include/php'), $cmd);
        $this->assertStringContainsString('-std=c++20', $cmd);
        $this->assertStringContainsString('-fno-rtti', $cmd);
    }

    public function testGccBuildLinkCommandIncludesPlatformPathsOptionsAndLibraries(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform, 'g++');

        $cmd = $compiler->buildLinkCommand(['a.o', 'b.o'], 'app', [
            'library_paths' => ['/usr/lib'],
            'libraries' => ['/usr/lib/libphpx.so', 'php'],
            'ldflags' => '-Wl,--as-needed',
            'build_mode' => 'ext',
        ]);

        $this->assertStringStartsWith('g++', $cmd);
        $this->assertStringContainsString('-L' . escapeshellarg('/usr/lib'), $cmd);
        $this->assertStringContainsString('-Wl,--as-needed', $cmd);
        $this->assertStringContainsString('-shared', $cmd);
        $this->assertStringContainsString('-lphpx', $cmd);
        $this->assertStringContainsString('-lphp', $cmd);
    }

    /**
     * 测试 GCC 完整编译选项
     */
    public function testGccFullCompileOptions(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        $options = $compiler->buildFullCompileOptions([
            'optimize' => 2,
            'debug' => false,
            'cpp_std' => 'c++17',
            'sanitize' => 'address',
            'pic' => true,
        ]);
        
        $this->assertStringContainsString('-O2', $options);
        $this->assertStringContainsString('-Wall', $options);
        $this->assertStringContainsString('-std=c++17', $options);
        $this->assertStringContainsString('-fsanitize=address', $options);
        $this->assertStringContainsString('-fPIC', $options);
    }

    /**
     * 测试 GCC 调试模式编译选项
     */
    public function testGccDebugCompileOptions(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        $options = $compiler->buildFullCompileOptions([
            'debug' => true,
        ]);
        
        $this->assertStringContainsString('-O0', $options);
        $this->assertStringContainsString('-g', $options);
    }

    /**
     * 测试 GCC 完整链接选项
     */
    public function testGccFullLinkOptions(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        $options = $compiler->buildFullLinkOptions([
            'shared' => true,
            'rpath' => ['/usr/lib', '/usr/local/lib'],
            'sanitize' => 'address',
        ]);
        
        $this->assertStringContainsString('-shared', $options);
        $this->assertStringContainsString('-Wl,-rpath', $options);
        $this->assertStringContainsString('-fsanitize=address', $options);
    }

    /**
     * 测试 Clang 编译器基本信息
     */
    public function testClangBasic(): void
    {
        $platform = new Linux();
        $compiler = new Clang($platform);
        
        $this->assertEquals('Clang', $compiler->getName());
        $this->assertEquals('clang++', $compiler->getCompilerCommand());
        $this->assertEquals('clang++', $compiler->getLinkerCommand());
    }

    /**
     * 测试 Clang Windows 平台链接器
     */
    public function testClangWindowsLinker(): void
    {
        $platform = new Windows();
        $compiler = new Clang($platform);
        
        // Windows 下 Clang 使用 link.exe
        $this->assertEquals('link', $compiler->getLinkerCommand());
    }

    public function testCompilerFactoryKeepsConfiguredCompilerCommand(): void
    {
        $compiler = CompilerFactory::createByName('/opt/llvm/bin/clang++', new Linux());

        $this->assertInstanceOf(Clang::class, $compiler);
        $this->assertSame('/opt/llvm/bin/clang++', $compiler->getCompilerCommand());
        $this->assertSame('/opt/llvm/bin/clang++', $compiler->getLinkerCommand());
    }

    /**
     * 测试 Clang 完整编译选项（Unix）
     */
    public function testClangUnixCompileOptions(): void
    {
        $platform = new Linux();
        $compiler = new Clang($platform);
        
        $options = $compiler->buildFullCompileOptions([
            'optimize' => 2,
            'cpp_std' => 'c++17',
        ]);
        
        $this->assertStringContainsString('-O2', $options);
        $this->assertStringContainsString('-Wall', $options);
        $this->assertStringContainsString('-std=c++17', $options);
        $this->assertStringNotContainsString('-fms-compatibility', $options);
    }

    /**
     * 测试 Clang Windows 编译选项
     */
    public function testClangWindowsCompileOptions(): void
    {
        $platform = new Windows();
        $compiler = new Clang($platform);
        
        $options = $compiler->buildFullCompileOptions([
            'optimize' => 2,
        ]);
        
        // Windows 下需要 MSVC 兼容模式
        $this->assertStringContainsString('-fms-compatibility', $options);
        $this->assertStringContainsString('-fms-compatibility-version=19.40', $options);
        $this->assertStringContainsString('-fdelayed-template-parsing', $options);
        $this->assertStringContainsString('-fms-extensions', $options);
    }

    /**
     * 测试 Clang 完整链接选项（Windows）
     */
    public function testClangWindowsLinkOptions(): void
    {
        $platform = new Windows();
        $compiler = new Clang($platform);
        
        $options = $compiler->buildFullLinkOptions([
            'debug' => true,
            'no_console' => true,
        ]);
        
        $this->assertStringContainsString('/DEBUG', $options);
        $this->assertStringContainsString('/SUBSYSTEM:WINDOWS', $options);
        $this->assertStringContainsString('/NODEFAULTLIB:LIBCMT', $options);
    }

    /**
     * 测试 Clang 完整链接选项（Unix）
     */
    public function testClangUnixLinkOptions(): void
    {
        $platform = new Linux();
        $compiler = new Clang($platform);
        
        $options = $compiler->buildFullLinkOptions([
            'shared' => true,
            'rpath' => ['/usr/lib'],
        ]);
        
        $this->assertStringContainsString('-shared', $options);
        $this->assertStringContainsString('-Wl,-rpath', $options);
    }

    /**
     * 测试不同优化级别
     */
    public function testOptimizationLevels(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        // O0 - 禁用优化
        $opt0 = $compiler->buildFullCompileOptions(['optimize' => 0]);
        $this->assertStringContainsString('-O0', $opt0);
        
        // O1
        $opt1 = $compiler->buildFullCompileOptions(['optimize' => 1]);
        $this->assertStringContainsString('-O1', $opt1);
        
        // O2
        $opt2 = $compiler->buildFullCompileOptions(['optimize' => 2]);
        $this->assertStringContainsString('-O2', $opt2);
        
        // O3
        $opt3 = $compiler->buildFullCompileOptions(['optimize' => 3]);
        $this->assertStringContainsString('-O3', $opt3);
    }

    /**
     * 测试默认值
     */
    public function testDefaultValues(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);
        
        // 不提供选项时使用默认值
        $options = $compiler->buildFullCompileOptions([]);
        
        $this->assertStringContainsString('-O2', $options); // 默认优化级别
        $this->assertStringContainsString('-Wall', $options); // 默认警告
    }
}
