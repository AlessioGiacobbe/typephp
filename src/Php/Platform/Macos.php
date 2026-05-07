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
}
