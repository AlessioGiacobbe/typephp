<?php

namespace PhpAot\Php\Backend;

use PhpAot\Php\Platform\PlatformBase;

/**
 * GCC 编译器后端实现
 */
class Gcc extends CompilerBackend
{
    public function getName(): string
    {
        return 'GCC';
    }

    public function getCompilerCommand(): string
    {
        return 'g++';
    }

    public function getLinkerCommand(): string
    {
        return 'g++';
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
        
        // 优化级别
        $optimizeLevel = $options['optimize'] ?? 2;
        $cmd .= ' -O' . $optimizeLevel;
        
        // 调试信息
        if (!empty($options['debug'])) {
            $cmd .= ' -g';
        }
        
        // 警告级别
        $cmd .= ' -Wall';
        
        // C++ 标准
        $cppStd = $options['cpp_std'] ?? 'c++17';
        $cmd .= ' -std=' . $cppStd;
        
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
        
        return $cmd;
    }
}
