<?php

namespace PhpAot\Php\Platform;

/**
 * macOS 平台实现
 */
class Macos extends PlatformBase
{
    public function getName(): string
    {
        return 'macOS';
    }

    public function isCurrent(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 6)) === 'DARWIN';
    }

    public function getIncludeFlags(array $includePaths): string
    {
        if (empty($includePaths)) {
            return '';
        }

        $flags = [];
        foreach ($includePaths as $path) {
            $flags[] = '-I' . escapeshellarg($path);
        }

        return implode(' ', $flags);
    }

    public function getLibraryPathFlags(array $libraryPaths): string
    {
        if (empty($libraryPaths)) {
            return '';
        }

        $flags = [];
        foreach ($libraryPaths as $path) {
            $flags[] = '-L' . escapeshellarg($path);
        }

        return implode(' ', $flags);
    }

    public function getLibraryFlags(array $libraries): string
    {
        if (empty($libraries)) {
            return '';
        }

        $flags = [];
        foreach ($libraries as $lib) {
            // 移除 lib 前缀和 .a/.dylib 后缀
            $libName = basename($lib);
            if (str_starts_with($libName, 'lib')) {
                $libName = substr($libName, 3);
            }
            $libName = preg_replace('/\.(a|dylib)$/', '', $libName);
            
            $flags[] = '-l' . $libName;
        }

        return implode(' ', $flags);
    }

    public function getObjectExtension(): string
    {
        return '.o';
    }

    public function getExecutableExtension(): string
    {
        return '';
    }

    public function getSharedLibraryExtension(): string
    {
        return '.dylib';
    }

    public function getPathSeparator(): string
    {
        return '/';
    }

    /**
     * 获取 RPATH 选项（macOS 需要绝对路径）
     */
    public function getRpathOptions(array $paths): string
    {
        if (empty($paths)) {
            return '';
        }

        $rpaths = [];
        foreach ($paths as $path) {
            $rpaths[] = '-Wl,-rpath,' . escapeshellarg($path);
        }

        return implode(' ', $rpaths);
    }

    /**
     * 获取 PIC 选项
     */
    public function getPicFlag(): string
    {
        return '-fPIC';
    }

    /**
     * 获取共享库链接选项
     */
    public function getSharedLinkFlag(): string
    {
        return '-dynamiclib';
    }

    /**
     * 获取当前安装名称选项
     */
    public function getCurrentInstallNameOption(string $path): string
    {
        return '-install_name ' . escapeshellarg($path);
    }

    /**
     * 构建 PHP 包含路径
     */
    public function buildPhpIncludePaths(string $phpDir): array
    {
        $paths = [
            $phpDir . '/include',
            $phpDir . '/include/main',
            $phpDir . '/include/TSRM',
            $phpDir . '/include/Zend',
        ];

        // 过滤不存在的路径
        return array_filter($paths, 'is_dir');
    }

    /**
     * 构建 PHP 库路径
     */
    public function buildPhpLibPaths(string $phpDir): array
    {
        $paths = [];
        
        $libPath = $phpDir . '/lib';
        if (is_dir($libPath)) {
            $paths[] = $libPath;
        }

        return $paths;
    }

    /**
     * 检测 PHP 库文件
     */
    public function detectPhpLibs(string $phpDir): array
    {
        $libPath = $phpDir . '/lib';
        
        if (!is_dir($libPath)) {
            throw new \RuntimeException("PHP lib directory not found: {$libPath}");
        }

        $embedLib = $libPath . '/libphp.dylib';
        $staticLib = $libPath . '/libphp.a';

        $hasEmbed = file_exists($embedLib);
        $hasStatic = file_exists($staticLib);

        if (!$hasEmbed && !$hasStatic) {
            throw new \RuntimeException('Neither libphp.dylib nor libphp.a found');
        }

        return [
            'embed' => $hasEmbed ? $embedLib : null,
            'static' => $hasStatic ? $staticLib : null,
            'is_shared' => $hasEmbed,
        ];
    }
}
