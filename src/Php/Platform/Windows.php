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

    /**
     * PHP SDK 路径
     */
    private string $phpSdkPath = '';

    public function __construct(array $phpLibs = [], bool $isZts = false, string $phpSdkPath = '')
    {
        $this->phpLibs = $phpLibs;
        $this->isZts = $isZts;
        $this->phpSdkPath = $phpSdkPath;
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
            $normalizedPath = str_replace('/', '\\', $path);
            $flags[] = '/I "' . $normalizedPath . '"';
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
            $normalizedPath = str_replace('/', '\\', $path);
            $flags[] = '/LIBPATH:"' . $normalizedPath . '"';
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
     * 获取 PHP SDK 路径
     */
    public function getPhpSdkPath(): string
    {
        return $this->phpSdkPath;
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

    /**
     * 构建 PHP SDK 包含路径
     */
    public function buildPhpSdkIncludePaths(string $phpDir): array
    {
        $phpSdkInclude = $phpDir . '\\SDK\\include';
        if (!is_dir($phpSdkInclude)) {
            throw new \RuntimeException("PHP SDK include directory not found: {$phpSdkInclude}");
        }

        $paths = [$phpSdkInclude];

        // 添加子目录
        $subDirs = ['main', 'Zend', 'TSRM', 'ext'];
        foreach ($subDirs as $subDir) {
            $subPath = $phpSdkInclude . '\\' . $subDir;
            if (is_dir($subPath)) {
                $paths[] = $subPath;
            }
        }

        return $paths;
    }

    /**
     * 构建 PHP SDK 库路径
     */
    public function buildPhpSdkLibPaths(string $phpDir): array
    {
        $paths = [];

        // 优先从 SDK/lib 读取
        $phpLib = $phpDir . '\\SDK\\lib';
        if (is_dir($phpLib)) {
            $paths[] = $phpLib;
        } else {
            // 备选：尝试直接从 lib 目录
            $phpLibAlt = $phpDir . '\\lib';
            if (is_dir($phpLibAlt)) {
                $paths[] = $phpLibAlt;
            }
        }

        return $paths;
    }

    /**
     * 检测 PHP lib 文件并决定 ZTS/NTS 模式
     */
    public function detectPhpLibs(string $phpDir): array
    {
        $phpDirs = [
            $phpDir . '\\SDK\\lib',
            $phpDir . '\\lib',
        ];

        $embedLibPath = '';
        $tsLibPath = '';
        $ntsLibPath = '';

        foreach ($phpDirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            if (empty($embedLibPath) && file_exists($dir . '\\php8embed.lib')) {
                $embedLibPath = $dir . '\\php8embed.lib';
            }

            if (empty($tsLibPath) && file_exists($dir . '\\php8ts.lib')) {
                $tsLibPath = $dir . '\\php8ts.lib';
            }

            if (empty($ntsLibPath) && file_exists($dir . '\\php8.lib')) {
                $ntsLibPath = $dir . '\\php8.lib';
            }

            if ($embedLibPath && ($tsLibPath || $ntsLibPath)) {
                break;
            }
        }

        if (!$embedLibPath) {
            throw new \RuntimeException('php8embed.lib not found');
        }

        if (!$tsLibPath && !$ntsLibPath) {
            throw new \RuntimeException('Neither php8ts.lib nor php8.lib found');
        }

        $isZts = !empty($tsLibPath);
        $coreLib = $isZts ? $tsLibPath : $ntsLibPath;

        return [
            'embed' => $embedLibPath,
            'core' => $coreLib,
            'is_zts' => $isZts,
        ];
    }
}
