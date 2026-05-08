<?php

namespace PhpAot\Php\Backend;

use PhpAot\Php\Platform\PlatformBase;

/**
 * GCC 编译器后端实现
 */
class Gcc extends CompilerBackend
{
    private string $compilerCommand;
    private string $linkerCommand;

    public function __construct(PlatformBase $platform, string $compilerCommand = 'g++', ?string $linkerCommand = null)
    {
        parent::__construct($platform);
        $this->compilerCommand = $compilerCommand;
        $this->linkerCommand = $linkerCommand ?? $compilerCommand;
    }

    public function getName(): string
    {
        return 'GCC';
    }

    public function getCompilerCommand(): string
    {
        return $this->compilerCommand;
    }

    public function getLinkerCommand(): string
    {
        return $this->linkerCommand;
    }

    public function compileFile(
        string $sourceFile,
        string $outputFile,
        array $includePaths = [],
        array $defines = [],
        array $flags = []
    ): string {
        $cmd = $this->getCompilerCommand();
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
        $cmd .= ' -o ' . escapeshellarg($outputFile);
        
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
        $cmd .= ' -c';
        $cmd .= ' ' . escapeshellarg($sourceFile);
        $cmd .= ' -o ' . escapeshellarg($outputFile);

        if (!empty($options['include_paths'])) {
            $cmd .= ' ' . $this->formatIncludePaths($options['include_paths']);
        }
        
        // 优化级别（C 文件通常使用较低的优化）
        $optimizeLevel = $options['optimize'] ?? 0;
        $cmd .= ' -O' . $optimizeLevel;
        
        // 调试信息
        if (!empty($options['debug'])) {
            $cmd .= ' -g';
        }
        
        // 警告级别
        $cmd .= ' -Wall';
        
        // 注意：C 文件不使用 -std=c++17 等 C++ 特定选项
        
        return $cmd;
    }

    public function buildLinkCommand(array $objectFiles, string $outputFile, array $options = []): string
    {
        $cmd = $this->getLinkerCommand();
        $cmd .= ' ' . implode(' ', array_map('escapeshellarg', $objectFiles));
        $cmd .= ' -o ' . escapeshellarg($outputFile);

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
        
        // 共享库
        if (!empty($options['shared'])) {
            $cmd .= ' ' . $this->platform->getSharedLinkFlag();
        }
        
        // RPATH
        if (!empty($options['rpath'])) {
            $cmd .= ' ' . $this->platform->getRpathOptions($options['rpath']);
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
        
        // Sanitizer
        if (!empty($config['sanitize'])) {
            if ($config['sanitize'] === 'address' || $config['sanitize'] === 'addr') {
                $cmd .= ' -fsanitize=address';
            } elseif ($config['sanitize'] === 'undefined' || $config['sanitize'] === 'undef') {
                $cmd .= ' -fsanitize=undefined';
            }
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
        
        // PIC (Position Independent Code)
        if ((!empty($config['build_mode']) && $config['build_mode'] === 'ext') || !empty($config['pic'])) {
            $cmd .= ' -fPIC';
        }
        
        // 性能分析宏
        if (!empty($config['enable_profiler'])) {
            $cmd .= ' -DPPROF_ON=1';
        }
        
        // 用户自定义编译标志
        if (!empty($config['cxxflags'])) {
            $cmd .= ' ' . $config['cxxflags'];
        }
        
        return $cmd;
    }

    /**
     * 构建链接选项（实现抽象方法）
     */
    public function buildLinkOptions(array $config = []): string
    {
        $cmd = '';
        
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
        
        // Sanitizer
        if (!empty($config['sanitize'])) {
            if ($config['sanitize'] === 'address' || $config['sanitize'] === 'addr') {
                $cmd .= ' -fsanitize=address';
            } elseif ($config['sanitize'] === 'undefined' || $config['sanitize'] === 'undef') {
                $cmd .= ' -fsanitize=undefined';
            }
        }
        
        return $cmd;
    }
}
