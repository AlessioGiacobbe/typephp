<?php

namespace PhpAot\Php\Backend;

use PhpAot\Php\Platform\Windows;

/**
 * MSVC 编译器后端实现
 */
class Msvc extends CompilerBackend
{
    public function __construct(Windows $platform)
    {
        parent::__construct($platform);
    }

    public function getName(): string
    {
        return 'MSVC';
    }

    public function getCompilerCommand(): string
    {
        return 'cl';
    }

    public function getLinkerCommand(): string
    {
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
        $cmd .= ' /c ' . escapeshellarg($sourceFile);
        $cmd .= ' /Fo' . escapeshellarg($outputFile);
        
        // 添加包含路径
        if (!empty($includePaths)) {
            $cmd .= ' ' . $this->formatIncludePaths($includePaths);
        }
        
        // 添加宏定义
        foreach ($defines as $define) {
            $cmd .= ' /D' . $define;
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
        $cmd .= ' /OUT:' . escapeshellarg($outputFile);
        
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
        $cmd .= ' /c';
        $cmd .= ' ' . escapeshellarg($sourceFile);
        $cmd .= ' /Fo' . escapeshellarg($outputFile);
        
        // 平台宏定义
        $cmd .= ' /DZEND_WIN32 /DPHP_WIN32 /DZEND_DEBUG=0';
        
        // ZTS 支持
        if ($this->platform instanceof Windows && $this->platform->isZts()) {
            $cmd .= ' /DZTS';
        }
        
        // 优化级别
        $optimizeLevel = $options['optimize'] ?? 2;
        $cmd .= ' /O' . ($optimizeLevel >= 2 ? '2' : ($optimizeLevel === 0 ? 'd' : '1'));
        
        // 警告级别
        $cmd .= ' /W3';
        
        // C++ 标准
        $cppStd = $options['cpp_std'] ?? 'c++17';
        $cmd .= ' /std:' . $cppStd;
        
        // 异常处理
        $cmd .= ' /EHsc';
        
        // CRT
        $cmd .= ' /MD';
        
        // nologo
        $cmd .= ' /nologo';
        
        return $cmd;
    }

    public function buildLinkCommand(array $objectFiles, string $outputFile, array $options = []): string
    {
        $cmd = $this->getLinkerCommand();
        $cmd .= ' ' . implode(' ', array_map('escapeshellarg', $objectFiles));
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
        
        // nologo
        $cmd .= ' /nologo';
        
        return $cmd;
    }
}
