<?php

namespace TypePhp\Tests\Backend;

use PHPUnit\Framework\TestCase;
use TypePhp\Platform\Windows;
use TypePhp\Platform\Linux;
use TypePhp\Platform\Macos;
use TypePhp\Backend\Msvc;
use TypePhp\Backend\Gcc;
use TypePhp\Backend\Clang;
use TypePhp\Backend\CompilerFactory;
use TypePhp\CompilerTest;
use TypePhp\Exception\TestError;

class BackendTest extends TestCase
{
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->temporaryDirectories as $dir) {
            $this->removeDirectory($dir);
        }
        $this->temporaryDirectories = [];
    }

    private function createTemporaryDirectory(string $prefix): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '_' . uniqid();
        mkdir($dir, 0777, true);
        $this->temporaryDirectories[] = $dir;
        return $dir;
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
        $compiler->cleanupResponseFile();
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

    public function testMsvcBuildCCompileCommandKeepsSharedCompilerOptions(): void
    {
        $platform = new Windows([], true);
        $compiler = new Msvc($platform);

        $cmd = $compiler->buildCCompileCommand('misc.c', 'misc.obj', [
            'sanitize' => 'address',
            'enable_profiler' => true,
            'prof_output' => 'app.prof',
            'user_defines' => ['FEATURE_X=1'],
            'lto' => true,
            'is_zts' => true,
        ]);

        $this->assertStringContainsString('/TC', $cmd);
        $this->assertStringContainsString('/fsanitize=address', $cmd);
        $this->assertStringContainsString('/DPPROF_ON=1', $cmd);
        $this->assertStringContainsString('/D' . escapeshellarg('PROF_OUTPUT_FILE="app.prof"'), $cmd);
        $this->assertStringContainsString('/DFEATURE_X=1', $cmd);
        $this->assertStringContainsString('/GL', $cmd);
        $this->assertStringContainsString('/DZTS', $cmd);
        $this->assertStringNotContainsString('/EHsc', $cmd);
        $this->assertStringNotContainsString('/std:', $cmd);
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
        $compiler->cleanupResponseFile();
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

    public function testGccBuildCCompileCommandKeepsSharedCompilerOptions(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform);

        $cmd = $compiler->buildCCompileCommand('misc.c', 'misc.o', [
            'sanitize' => 'address',
            'enable_profiler' => true,
            'prof_output' => 'app.prof',
            'user_defines' => ['FEATURE_X=1'],
            'lto' => true,
            'march' => 'native',
            'target_platform' => 'aarch64-linux-gnu',
            'build_mode' => 'ext',
        ]);

        $this->assertStringContainsString('-fsanitize=address', $cmd);
        $this->assertStringContainsString('-DPPROF_ON=1', $cmd);
        $this->assertStringContainsString('-D' . escapeshellarg('PROF_OUTPUT_FILE="app.prof"'), $cmd);
        $this->assertStringContainsString('-DFEATURE_X=1', $cmd);
        $this->assertStringContainsString('-flto', $cmd);
        $this->assertStringContainsString('-march=native', $cmd);
        $this->assertStringContainsString('--target=aarch64-linux-gnu', $cmd);
        $this->assertStringContainsString('-fPIC', $cmd);
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
        $compiler->cleanupResponseFile();
    }

    public function testBuildCleansResponseFileWhenLinkFails(): void
    {
        $dir = $this->createTemporaryDirectory('backend_link_failure');
        $target = $dir . '/app';
        $backend = new Gcc(new Linux(), 'false');
        $compiler = new class(ROOT_PATH, $target, $backend) extends CompilerTest {
            public function __construct(
                string $rootPath,
                private readonly string $testTarget,
                \TypePhp\Backend\CompilerBackend $backend
            ) {
                parent::__construct($rootPath);
                $this->forTest = true;
                $this->compilerBackend = $backend;
            }

            protected function getTargetFileName(): string
            {
                return $this->testTarget;
            }
        };

        // getLibraries() 必须先找到 phpx 库才能走到链接失败路径。
        // 用临时目录提供静态库占位文件，使测试不依赖本机是否已构建 phpx。
        $phpxHome = $dir . '/phpx';
        mkdir($phpxHome . '/lib', 0777, true);
        touch($phpxHome . '/lib/libphpx.a');
        $previousPhpxHome = getenv('PHPX_HOME');
        putenv('PHPX_HOME=' . $phpxHome);

        try {
            $compiler->build([$dir . '/missing.o']);
            $this->fail('The failing linker command should abort the build');
        } catch (TestError $e) {
            $this->assertStringContainsString('link failed', $e->getMessage());
        } finally {
            if ($previousPhpxHome === false) {
                putenv('PHPX_HOME');
            } else {
                putenv('PHPX_HOME=' . $previousPhpxHome);
            }
        }

        $this->assertFileDoesNotExist($target . '.rsp');
    }

    public function testResponseFileArgumentIsEscapedForPathsWithSpaces(): void
    {
        $platform = new Linux();
        $compiler = new Gcc($platform, 'g++');
        $dir = $this->createTemporaryDirectory('backend link path');
        $target = $dir . '/my app';
        $rspFile = $target . '.rsp';
        $objectWithSpace = $dir . '/object one.o';
        $objectWithoutSpace = $dir . '/object_two.o';

        $cmd = $compiler->buildLinkCommand([$objectWithSpace, $objectWithoutSpace], $target);

        $this->assertStringContainsString(escapeshellarg('@' . $rspFile), $cmd);
        $this->assertStringContainsString('-o ' . escapeshellarg($target), $cmd);
        $this->assertFileExists($rspFile);

        $lines = file($rspFile, FILE_IGNORE_NEW_LINES);
        $this->assertSame('"' . $objectWithSpace . '"', $lines[0]);
        $this->assertSame('"' . $objectWithoutSpace . '"', $lines[1]);
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
