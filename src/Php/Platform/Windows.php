<?php

namespace PhpAot\Php\Platform;

/**
 * Windows 平台实现
 */
class Windows extends PlatformBase
{
    /**
     * PHP 库文件信息
     */
    private array $phpLibs = [];

    /**
     * 是否为 ZTS 模式
     */
    private bool $isZts = false;

    public function __construct(array $phpLibs = [], bool $isZts = false)
    {
        $this->phpLibs = $phpLibs;
        $this->isZts = $isZts;
    }

    public function getName(): string
    {
        return 'Windows';
    }

    public function isCurrent(): bool
    {
        return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    }

    public function getIncludeFlags(array $includePaths): string
    {
        if (empty($includePaths)) {
            return '';
        }

        $flags = [];
        foreach ($includePaths as $path) {
            $flags[] = '/I "' . $path . '"';
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
            $flags[] = '/LIBPATH:"' . $path . '"';
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
            $flags[] = '"' . $lib . '"';
        }

        return implode(' ', $flags);
    }

    public function getObjectExtension(): string
    {
        return '.obj';
    }

    public function getExecutableExtension(): string
    {
        return '.exe';
    }

    public function getSharedLibraryExtension(): string
    {
        return '.dll';
    }

    public function getPathSeparator(): string
    {
        return '\\';
    }

    /**
     * 获取 PHP 库文件列表
     */
    public function getPhpLibs(): array
    {
        return $this->phpLibs;
    }

    /**
     * 判断是否为 ZTS 模式
     */
    public function isZts(): bool
    {
        return $this->isZts;
    }

    /**
     * 获取 Windows 子系统选项
     */
    public function getSubsystemOptions(bool $noConsole): string
    {
        if (!$noConsole) {
            return '';
        }

        return '/SUBSYSTEM:WINDOWS /ENTRY:mainCRTStartup';
    }

    /**
     * 获取 CRT 库配置
     */
    public function getCrtConfig(): string
    {
        return '/NODEFAULTLIB:LIBCMT';
    }

    /**
     * 获取调试选项
     */
    public function getDebugOptions(bool $debugInfo): string
    {
        if (!$debugInfo) {
            return '';
        }

        return '/DEBUG';
    }
}
