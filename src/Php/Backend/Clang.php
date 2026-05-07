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
}
