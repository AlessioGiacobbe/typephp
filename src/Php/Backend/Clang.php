<?php

namespace PhpAot\Php\Backend;

use PhpAot\Php\Platform\PlatformBase;

/**
 * Clang 编译器后端实现
 */
class Clang extends CompilerBackend
{
    public function getName(): string
    {
        return 'Clang';
    }

    public function getCompilerCommand(): string
    {
        return 'clang++';
    }

    public function getLinkerCommand(): string
    {
        // Windows 下使用 MSVC 链接器，其他平台使用 clang++
        if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
            return 'link';
        }
        return 'clang++';
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
        
        // 优化级别
        $optimizeLevel = $options['optimize'] ?? 2;
        
        // 调试模式
        if (!empty($options['debug'])) {
            $cmd .= ' -O0 -g';
        } else {
            $cmd .= ' -O' . $optimizeLevel;
        }
        
        // 警告级别
        $cmd .= ' -Wall';
        
        // C++ 标准
        $cppStd = $options['cpp_std'] ?? 'c++17';
        $cmd .= ' -std=' . $cppStd;
        
        // Sanitizer 支持
        if (!empty($options['sanitize'])) {
            $cmd .= ' -fsanitize=' . $options['sanitize'];
        }
        
        // PIC（位置无关代码）
        if (!empty($options['pic'])) {
            $cmd .= ' -fPIC';
        }
        
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
        $cmd .= ' ' . escapeshellarg($sourceFile);
        $cmd .= ' -o ' . escapeshellarg($outputFile);
        
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

    public function buildLinkCommand(array $objectFiles, string $outputFile, array $options = []): string
    {
        $cmd = $this->getLinkerCommand();
        $cmd .= ' ' . implode(' ', array_map('escapeshellarg', $objectFiles));
        
        // Windows 使用 MSVC 链接器语法
        if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
            $cmd .= ' /OUT:' . escapeshellarg($outputFile);
            
            // 调试信息
            if (!empty($options['debug'])) {
                $cmd .= ' /DEBUG';
            }
            
            // Windows 子系统
            if (!empty($options['no_console'])) {
                $cmd .= ' ' . $this->platform->getSubsystemOptions(true);
            }
            
            // CRT 配置
            $cmd .= ' ' . $this->platform->getCrtConfig();
        } else {
            // Unix/Linux/macOS 使用 GCC 风格语法
            $cmd .= ' -o ' . escapeshellarg($outputFile);
            
            // 共享库
            if (!empty($options['shared'])) {
                $cmd .= ' ' . $this->platform->getSharedLinkFlag();
                
                // macOS 需要 install_name
                if ($this->platform instanceof \PhpAot\Php\Platform\Macos && !empty($options['install_name'])) {
                    $cmd .= ' ' . $this->platform->getCurrentInstallNameOption($options['install_name']);
                }
            }
            
            // RPATH（运行时库搜索路径）
            if (!empty($options['rpath'])) {
                $cmd .= ' ' . $this->platform->getRpathOptions($options['rpath']);
            }
            
            // Sanitizer 链接选项
            if (!empty($options['sanitize'])) {
                $cmd .= ' -fsanitize=' . $options['sanitize'];
            }
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
        
        // PIC (Position Independent Code)
        if (!empty($config['build_mode']) && $config['build_mode'] === 'ext') {
            if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
                // Windows Clang 不需要特殊处理
            } else {
                $cmd .= ' -fPIC';
            }
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
            if (!empty($config['build_mode']) && $config['build_mode'] === 'ext') {
                $cmd .= ' -shared';
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
        
        return $cmd;
    }
}
