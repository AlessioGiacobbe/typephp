<?php

namespace PhpAot\Php\Platform;

/**
 * macOS 平台实现
 */
class Macos extends UnixPlatform
{
    public function getName(): string
    {
        return 'macOS';
    }

    public function isCurrent(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 6)) === 'DARWIN';
    }

    public function getSharedLibraryExtension(): string
    {
        return '.dylib';
    }

    public function getDefaultCompiler(): string
    {
        return 'clang++';
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
     * 获取默认的 RPATH 路径列表（macOS 需要）
     */
    public function getDefaultRpaths(?string $phpxDir = null, ?string $phpDir = null): array
    {
        $rpaths = [];

        if ($phpxDir !== null) {
            $phpxLibDir = $phpxDir . '/lib';
            if (is_dir($phpxLibDir)) {
                $rpaths[] = $phpxLibDir;
            }
        }

        if ($phpDir !== null) {
            $phpLibDir = $phpDir . '/lib';
            if (is_dir($phpLibDir)) {
                $rpaths[] = $phpLibDir;
            }
        }

        return $rpaths;
    }
}
