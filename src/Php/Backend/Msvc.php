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
        
        // 禁用常见警告
        if (!empty($options['suppressed_warnings'])) {
            foreach ($options['suppressed_warnings'] as $code => $description) {
                $cmd .= " /wd{$code}";
            }
        }
        
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

    /**
     * 构建 C 文件的编译命令（不包含 C++ 特定选项）
     */
    public function buildCCompileCommand(string $sourceFile, string $outputFile, array $options = []): string
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
        
        // 优化级别（C 文件通常使用较低的优化）
        $optimizeLevel = $options['optimize'] ?? 0;
        $cmd .= ' /O' . ($optimizeLevel >= 2 ? '2' : ($optimizeLevel === 0 ? 'd' : '1'));
        
        // 警告级别
        $cmd .= ' /W3';
        
        // 禁用常见警告
        $suppressedWarnings = $options['suppressed_warnings'] ?? ['4244', '4146'];
        foreach ($suppressedWarnings as $code) {
            $cmd .= " /wd{$code}";
        }
        
        // nologo
        $cmd .= ' /nologo';
        
        // 注意：C 文件不使用 /EHsc, /std:c++17, /MD 等 C++ 特定选项
        
        return $cmd;
    }

    public function buildLinkCommand(array $objectFiles, string $outputFile, array $options = []): string
    {
        $cmd = $this->getLinkerCommand();
        $cmd .= ' ' . implode(' ', array_map('escapeshellarg', $objectFiles));
        $cmd .= ' /OUT:' . escapeshellarg($outputFile);
        
        // 调试信息
        if (!empty($options['debug_info'])) {
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

    /**
     * 构建编译单个文件的完整命令
     */
    public function buildCompileFileCommand(
        string $sourceFile,
        string $objectFile,
        array $includePaths = [],
        array $defines = [],
        array $options = []
    ): string {
        $cmd = $this->getCompilerCommand();
        $cmd .= ' /c ' . escapeshellarg($sourceFile);
        $cmd .= ' /Fo' . escapeshellarg($objectFile);
        
        // 包含路径
        if (!empty($includePaths)) {
            $cmd .= ' ' . $this->formatIncludePaths($includePaths);
        }
        
        // 宏定义
        foreach ($defines as $define) {
            $cmd .= ' /D' . $define;
        }
        
        // 编译选项
        $cmd .= $this->buildFullCompileOptions($options);
        
        return $cmd;
    }

    /**
     * 构建完整的编译选项
     */
    public function buildFullCompileOptions(array $options = []): string
    {
        $cmd = '';
        
        // 平台宏定义
        $cmd .= ' /DZEND_WIN32 /DPHP_WIN32 /DZEND_DEBUG=0';
        
        // ZTS
        if ($this->platform instanceof Windows && $this->platform->isZts()) {
            $cmd .= ' /DZTS';
        }
        
        // Sanitizer
        if (!empty($options['sanitize'])) {
            if ($options['sanitize'] === 'address' || $options['sanitize'] === 'addr') {
                $cmd .= ' /fsanitize=address';
            }
        }
        
        // 优化和调试
        if (!empty($options['debug_info'])) {
            $cmd .= ' /Od /Zi';
        } else {
            $optimizeLevel = $options['optimize'] ?? 2;
            $optMap = [0 => '/Od', 1 => '/O2', 2 => '/O2', 3 => '/Ox'];
            $cmd .= ' ' . ($optMap[$optimizeLevel] ?? '/O2');
        }
        
        // 警告
        $cmd .= ' /W3';
        
        // 禁用常见警告（只使用键，即警告代码）
        if (!empty($options['suppressed_warnings'])) {
            foreach ($options['suppressed_warnings'] as $code => $description) {
                $cmd .= " /wd{$code}";
            }
        }
        
        // C++ 选项
        $cmd .= ' /EHsc';
        if (!empty($options['cpp_std'])) {
            $cmd .= ' /std:' . $options['cpp_std'];
        }
        
        // CRT
        $cmd .= ' /MD';
        
        // nologo
        $cmd .= ' /nologo';
        
        return $cmd;
    }

    /**
     * 构建完整的链接选项
     */
    public function buildFullLinkOptions(array $options = []): string
    {
        $cmd = '';
        
        // 调试
        if (!empty($options['debug_info'])) {
            $cmd .= ' /DEBUG';
        }
        
        // Windows 子系统
        if (!empty($options['no_console'])) {
            $cmd .= ' ' . $this->platform->getSubsystemOptions(true);
        }
        
        // CRT
        $cmd .= ' ' . $this->platform->getCrtConfig();
        
        // DLL
        if (!empty($options['shared'])) {
            $cmd .= ' /DLL';
        }
        
        // nologo
        $cmd .= ' /nologo';
        
        return $cmd;
    }

    /**
     * 构建编译选项（实现抽象方法）
     */
    public function buildCompileOptions(array $config = []): string
    {
        $cmd = '';
        
        // 平台宏定义
        $cmd .= ' /DZEND_WIN32 /DPHP_WIN32 /DZEND_DEBUG=0';
        
        // ZTS
        if (!empty($config['is_zts'])) {
            $cmd .= ' /DZTS';
        }
        
        // Sanitizer
        if (!empty($config['sanitize'])) {
            if ($config['sanitize'] === 'address' || $config['sanitize'] === 'addr') {
                $cmd .= ' /fsanitize=address';
            }
        }
        
        // 优化和调试
        if (!empty($config['debug_info'])) {
            $cmd .= ' /Od /Zi';
        } else {
            $optimizeLevel = $config['optimize'] ?? 2;
            $optMap = [0 => '/Od', 1 => '/O2', 2 => '/O2', 3 => '/Ox'];
            $cmd .= ' ' . ($optMap[$optimizeLevel] ?? '/O2');
        }
        
        // 警告
        $cmd .= ' /W3';
        
        // 禁用常见警告（只使用键，即警告代码）
        if (!empty($config['suppressed_warnings'])) {
            foreach ($config['suppressed_warnings'] as $code => $description) {
                $cmd .= " /wd{$code}";
            }
        }
        
        // C++ 选项
        $cmd .= ' /EHsc';
        if (!empty($config['cpp_std'])) {
            $cmd .= ' /std:' . $config['cpp_std'];
        }
        
        // 扩展模块选项
        if (!empty($config['build_mode']) && $config['build_mode'] === 'ext') {
            // MSVC 不需要 -fPIC，默认就是位置无关代码
        }
        
        // CRT
        $cmd .= ' /MD';
        
        // nologo
        $cmd .= ' /nologo';
        
        // 性能分析宏
        if (!empty($config['enable_profiler'])) {
            $cmd .= ' /DPPROF_ON=1';
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
        
        // 调试
        if (!empty($config['debug_info'])) {
            $cmd .= ' /DEBUG';
        }
        
        // Windows 子系统
        if (!empty($config['no_console'])) {
            $cmd .= ' ' . $this->platform->getSubsystemOptions(true);
        }
        
        // CRT 配置
        $cmd .= ' ' . $this->platform->getCrtConfig();
        
        // 扩展模块选项
        if (!empty($config['build_mode']) && $config['build_mode'] === 'ext') {
            $cmd .= ' /DLL';
        }
        
        // nologo
        $cmd .= ' /nologo';
        
        return $cmd;
    }
}
