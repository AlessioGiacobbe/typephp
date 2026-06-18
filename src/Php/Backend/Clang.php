<?php

namespace PhpAot\Php\Backend;

use PhpAot\Php\Platform\PlatformBase;

/**
 * Clang 编译器后端实现
 */
class Clang extends CompilerBackend
{
    private string $compilerCommand;
    private ?string $linkerCommand;

    public function __construct(PlatformBase $platform, string $compilerCommand = 'clang++', ?string $linkerCommand = null)
    {
        parent::__construct($platform);
        $this->compilerCommand = $compilerCommand;
        $this->linkerCommand = $linkerCommand;
    }

    public function getName(): string
    {
        return 'Clang';
    }

    public function getCompilerCommand(): string
    {
        return $this->compilerCommand;
    }

    public function getLinkerCommand(): string
    {
        if ($this->linkerCommand !== null) {
            return $this->linkerCommand;
        }

        // Windows 下使用 MSVC 链接器，其他平台使用 clang++
        if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
            return 'link';
        }
        return $this->compilerCommand;
    }

    /**
     * Windows 下优先使用 lld-link，找不到时回退到 link.exe
     */
    public static function detectWindowsLinker(): string
    {
        $output = [];
        $returnCode = 0;
        exec('lld-link --version 2>&1', $output, $returnCode);

        if ($returnCode === 0) {
            return 'lld-link';
        }

        $llvmHome = getenv('LLVM_HOME');
        if ($llvmHome && is_dir($llvmHome)) {
            $lldLinkPath = rtrim($llvmHome, '\/') . '\x64\bin\lld-link.exe';
            if (file_exists($lldLinkPath)) {
                exec('"' . $lldLinkPath . '" --version 2>&1', $output, $returnCode);
                if ($returnCode === 0) {
                    $lldDir = dirname($lldLinkPath);
                    putenv("PATH={$lldDir};" . getenv('PATH'));
                    return 'lld-link';
                }
            }
        }

        return 'link';
    }

    public function compileFile(
        string $sourceFile,
        string $outputFile,
        array $includePaths = [],
        array $defines = [],
        array $flags = []
    ): string {
        $cmd = $this->getCompilerCommand();
        
        // Windows 下需要 MSVC 兼容模式
        if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
            $cmd .= ' -fms-compatibility';
            $cmd .= ' -fms-compatibility-version=19.40';
            $cmd .= ' -fdelayed-template-parsing';
            $cmd .= ' -fms-extensions';
        }
        
        $cmd .= ' -c ' . escapeshellarg($sourceFile);
        $cmd .= ' -o ' . escapeshellarg($outputFile);
        
        // 添加包含路径
        if (!empty($includePaths)) {
            $cmd .= ' ' . $this->formatIncludePaths($includePaths);
        }
        
        // 添加宏定义
        foreach ($defines as $define) {
            $cmd .= ' -D' . $define;
        }
        
        // 添加额外标志
        if (!empty($flags)) {
            $cmd .= ' ' . implode(' ', $flags);
        }
        
        return $cmd;
    }

    public function linkObjects(
        array $objectFiles,
        string $outputFile,
        array $libraryPaths = [],
        array $libraries = [],
        array $flags = []
    ): string {
        $cmd = $this->getLinkerCommand();
        
        // 添加目标文件
        $cmd .= ' ' . implode(' ', array_map('escapeshellarg', $objectFiles));
        
        // 输出文件
        if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
            $cmd .= ' /OUT:' . escapeshellarg($outputFile);
        } else {
            $cmd .= ' -o ' . escapeshellarg($outputFile);
        }
        
        // 添加库路径
        if (!empty($libraryPaths)) {
            $cmd .= ' ' . $this->formatLibraryPaths($libraryPaths);
        }
        
        // 添加库文件
        if (!empty($libraries)) {
            $cmd .= ' ' . $this->formatLibraries($libraries);
        }
        
        // 添加额外标志
        if (!empty($flags)) {
            $cmd .= ' ' . implode(' ', $flags);
        }
        
        return $cmd;
    }

    public function buildCompileCommand(string $sourceFile, string $outputFile, array $options = []): string
    {
        $cmd = $this->getCompilerCommand();
        
        // Windows 下需要 MSVC 兼容模式
        if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
            $cmd .= ' -fms-compatibility';
            $cmd .= ' -fms-compatibility-version=19.40';
            $cmd .= ' -fdelayed-template-parsing';
            $cmd .= ' -fms-extensions';
        }
        
        $cmd .= ' -c';
        $cmd .= ' ' . escapeshellarg($sourceFile);
        $cmd .= ' -o ' . escapeshellarg($outputFile);

        if (!empty($options['include_paths'])) {
            $cmd .= ' ' . $this->formatIncludePaths($options['include_paths']);
        }

        $cmd .= $this->buildCompileOptions($options);

        return $cmd;
    }

    /**
     * 构建 C 文件的编译命令（不包含 C++ 特定选项）
     */
    public function buildCCompileCommand(string $sourceFile, string $outputFile, array $options = []): string
    {
        $cmd = $this->getCompilerCommand();

        // Windows 下需要 MSVC 兼容模式
        if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
            $cmd .= ' -fms-compatibility';
            $cmd .= ' -fms-compatibility-version=19.40';
            $cmd .= ' -fdelayed-template-parsing';
            $cmd .= ' -fms-extensions';
        }

        $cmd .= ' -c';
        $cmd .= ' -x c';
        $cmd .= ' ' . escapeshellarg($sourceFile);
        $cmd .= ' -o ' . escapeshellarg($outputFile);

        if (!empty($options['include_paths'])) {
            $cmd .= ' ' . $this->formatIncludePaths($options['include_paths']);
        }

        // 优化级别（C 文件通常使用较低的优化）
        $optimizeLevel = $options['optimize'] ?? 0;

        // 调试模式
        if (!empty($options['debug'])) {
            $cmd .= ' -O0 -g';
        } else {
            $cmd .= ' -O' . $optimizeLevel;
        }

        // 警告级别
        $cmd .= ' -Wall';

        // 注意：C 文件不使用 -std=c++17 等 C++ 特定选项

        return $cmd;
    }

    /**
     * 构建原生源文件的编译命令（汇编/Objective-C 等）
     *
     * @param string $language 语言标识（assembler, objective-c, objective-c++）
     */
    public function buildNativeCompileCommand(string $sourceFile, string $outputFile, array $options = [], string $language = ''): string
    {
        $cmd = $this->getCompilerCommand();

        // Windows 下需要 MSVC 兼容模式
        if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
            $cmd .= ' -fms-compatibility';
            $cmd .= ' -fms-compatibility-version=19.40';
            $cmd .= ' -fdelayed-template-parsing';
            $cmd .= ' -fms-extensions';
        }

        $cmd .= ' -c';
        if ($language !== '') {
            $cmd .= ' -x ' . $language;
        }
        $cmd .= ' ' . escapeshellarg($sourceFile);
        $cmd .= ' -o ' . escapeshellarg($outputFile);

        if (!empty($options['include_paths'])) {
            $cmd .= ' ' . $this->formatIncludePaths($options['include_paths']);
        }

        $cmd .= $this->buildCompileOptions($options);

        return $cmd;
    }

    public function buildLinkCommand(array $objectFiles, string $outputFile, array $options = []): string
    {
        $cmd = $this->getLinkerCommand();
        $cmd .= ' ' . implode(' ', array_map('escapeshellarg', $objectFiles));
        
        // Windows 使用 MSVC 链接器语法
        if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
            $cmd .= ' /OUT:' . escapeshellarg($outputFile);
            
        } else {
            $cmd .= ' -o ' . escapeshellarg($outputFile);
        }

        if (!empty($options['library_paths'])) {
            $cmd .= ' ' . $this->formatLibraryPaths($options['library_paths']);
        }

        if (!empty($options['ldflags'])) {
            $cmd .= ' ' . $options['ldflags'];
        }

        $cmd .= $this->buildLinkOptions($options);

        if (!empty($options['libraries'])) {
            $cmd .= ' ' . $this->formatLibraries($options['libraries']);
        }
        
        return $cmd;
    }

    /**
     * 构建完整的编译选项
     */
    public function buildFullCompileOptions(array $options = []): string
    {
        $cmd = '';
        
        // Windows MSVC 兼容模式
        if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
            $cmd .= ' -fms-compatibility';
            $cmd .= ' -fms-compatibility-version=19.40';
            $cmd .= ' -fdelayed-template-parsing';
            $cmd .= ' -fms-extensions';
        }
        
        // 优化级别
        if (!empty($options['debug'])) {
            $cmd .= ' -O0 -g';
        } else {
            $optimizeLevel = $options['optimize'] ?? 2;
            $cmd .= ' -O' . $optimizeLevel;
        }
        
        // 警告
        $cmd .= ' -Wall';
        
        // C++ 标准
        if (!empty($options['cpp_std'])) {
            $cmd .= ' -std=' . $options['cpp_std'];
        }
        
        // 目标 CPU 指令集
        if (!empty($options['march'])) {
            $cmd .= ' -march=' . $options['march'];
        }
        
        // Sanitizer
        if (!empty($options['sanitize'])) {
            $cmd .= ' -fsanitize=' . $options['sanitize'];
        }
        
        // PIC
        if (!empty($options['pic'])) {
            $cmd .= ' -fPIC';
        }
        
        return $cmd;
    }

    /**
     * 构建完整的链接选项
     */
    public function buildFullLinkOptions(array $options = []): string
    {
        $cmd = '';
        
        // Windows 特定选项
        if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
            // 调试
            if (!empty($options['debug'])) {
                $cmd .= ' /DEBUG';
            }
            
            // Windows 子系统
            if (!empty($options['no_console'])) {
                $cmd .= ' ' . $this->platform->getSubsystemOptions(true);
            }
            
            // CRT
            $cmd .= ' ' . $this->platform->getCrtConfig();
        } else {
            // Unix/Linux/macOS
            // 共享库
            if (!empty($options['shared'])) {
                $cmd .= ' ' . $this->platform->getSharedLinkFlag();
            }
            
            // RPATH
            if (!empty($options['rpath'])) {
                $cmd .= ' ' . $this->platform->getRpathOptions($options['rpath']);
            }
        }
        
        // Sanitizer
        if (!empty($options['sanitize'])) {
            $cmd .= ' -fsanitize=' . $options['sanitize'];
        }
        
        return $cmd;
    }

    /**
     * 构建编译选项（实现抽象方法）
     */
    public function buildCompileOptions(array $config = []): string
    {
        $cmd = '';
        
        // Windows MSVC 兼容模式
        if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
            $cmd .= ' -fms-compatibility';
            $cmd .= ' -fms-compatibility-version=19.40';
            $cmd .= ' -fdelayed-template-parsing';
            $cmd .= ' -fms-extensions';
        }
        
        // Sanitizer
        if (!empty($config['sanitize'])) {
            $cmd .= ' -fsanitize=' . $config['sanitize'];
        }
        
        // 优化和调试
        if (!empty($config['debug'])) {
            $cmd .= ' -O0 -g';
        } else {
            $optimizeLevel = $config['optimize'] ?? 2;
            $cmd .= ' -O' . $optimizeLevel;
        }
        
        // 警告
        $cmd .= ' -Wall';
        
        // C++ 标准
        if (!empty($config['cpp_std'])) {
            $cmd .= ' -std=' . $config['cpp_std'];
        }
        
        // 目标 CPU 指令集
        if (!empty($config['march'])) {
            $cmd .= ' -march=' . $config['march'];
        }
        
        // PIC (Position Independent Code)
        if ((!empty($config['build_mode']) && $config['build_mode'] === 'ext') || !empty($config['pic'])) {
            if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
                // Windows Clang 不需要特殊处理
            } else {
                $cmd .= ' -fPIC';
            }
        }
        
        // 性能分析宏
        if (!empty($config['enable_profiler'])) {
            $cmd .= ' -DPPROF_ON=1';
            if (!empty($config['prof_output'])) {
                $cmd .= ' -DPROF_OUTPUT_FILE=\'"' . $config['prof_output'] . '"\'';
            }
        }
        
        // 用户自定义编译标志
        if (!empty($config['cxxflags'])) {
            $cmd .= ' ' . $config['cxxflags'];
        }
        
        // 用户自定义预处理器宏
        if (!empty($config['user_defines'])) {
            foreach ($config['user_defines'] as $define) {
                $cmd .= ' -D' . $define;
            }
        }

        // LTO（链接时优化）
        if (!empty($config['lto'])) {
            $cmd .= ' -flto';
        }

        return $cmd;
    }

    /**
     * 构建链接选项（实现抽象方法）
     */
    public function buildLinkOptions(array $config = []): string
    {
        $cmd = '';
        
        // Windows 特定选项
        if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
            // 调试
            if (!empty($config['debug'])) {
                $cmd .= ' /DEBUG';
            }
            
            // Windows 子系统
            if (!empty($config['no_console'])) {
                $cmd .= ' ' . $this->platform->getSubsystemOptions(true);
            }
            
            // CRT
            $cmd .= ' ' . $this->platform->getCrtConfig();
            
            // 扩展模块选项
            if (!empty($config['build_mode']) && $config['build_mode'] === 'ext') {
                $cmd .= ' /DLL';
            }
        } else {
            // Unix/Linux/macOS
            // 扩展模块选项
            if ((!empty($config['build_mode']) && $config['build_mode'] === 'ext') || !empty($config['shared'])) {
                $cmd .= ' ' . $this->platform->getSharedLinkFlag();

                if ($this->platform instanceof \PhpAot\Php\Platform\Macos && !empty($config['install_name'])) {
                    $cmd .= ' ' . $this->platform->getCurrentInstallNameOption($config['install_name']);
                }
            }
            
            // RPATH
            if (!empty($config['rpath'])) {
                foreach ($config['rpath'] as $path) {
                    $cmd .= ' -Wl,-rpath,' . escapeshellarg($path);
                }
            }
        }
        
        // Sanitizer
        if (!empty($config['sanitize'])) {
            $cmd .= ' -fsanitize=' . $config['sanitize'];
        }

        // LTO（链接时优化）
        if (!empty($config['lto'])) {
            $cmd .= ' -flto';
        }

        return $cmd;
    }
}
