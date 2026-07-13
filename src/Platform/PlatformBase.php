<?php

namespace TypePhp\Platform;

/**
 * 平台抽象基类
 * 定义所有平台必须实现的接口
 */
abstract class PlatformBase
{
    /**
     * 获取平台名称
     */
    abstract public function getName(): string;

    /**
     * 判断是否为当前平台
     */
    abstract public function isCurrent(): bool;

    /**
     * 获取编译器包含路径参数
     */
    abstract public function getIncludeFlags(array $includePaths): string;

    /**
     * 获取链接器库路径参数
     */
    abstract public function getLibraryPathFlags(array $libraryPaths): string;

    /**
     * 获取链接库参数
     */
    abstract public function getLibraryFlags(array $libraries): string;

    /**
     * 获取文件扩展名
     */
    abstract public function getObjectExtension(): string;

    /**
     * 获取可执行文件扩展名
     */
    abstract public function getExecutableExtension(): string;

    /**
     * 获取动态库扩展名
     */
    abstract public function getSharedLibraryExtension(): string;

    /**
     * 获取路径分隔符
     */
    abstract public function getPathSeparator(): string;

    /**
     * 获取该平台默认使用的 C++ 编译器命令
     */
    abstract public function getDefaultCompiler(): string;

    /**
     * 获取 PHP 安装目录
     */
    abstract public function getPhpDir(): string;

    /**
     * 构建 PHP 包含路径
     */
    abstract public function buildPhpIncludePaths(string $phpDir): array;

    /**
     * 构建 PHP 库路径
     */
    abstract public function buildPhpLibPaths(string $phpDir): array;

    /**
     * 检测 PHP 库文件
     */
    abstract public function detectPhpLibs(string $phpDir): array;

    /**
     * 获取指定构建模式的目标文件扩展名
     */
    public function getTargetExtension(string $buildMode): string
    {
        return ($buildMode === 'ext' || $buildMode === 'lib')
            ? '.so'
            : $this->getExecutableExtension();
    }

    /**
     * 获取构建前的运行库检查告警
     */
    public function getBuildLibraryWarnings(string $phpDir, string $phpxDir, string $buildMode): array
    {
        if ($buildMode !== 'bin' && $buildMode !== 'lib') {
            return [];
        }

        $ext = ltrim($this->getSharedLibraryExtension(), '.');
        $warnings = [];

        if (!is_file($phpDir . '/lib/libphp.' . $ext)) {
            $warnings[] = [
                'warning' => "The `libphp.{$ext}` is not found",
                'info' => 'Run tpc.php in an interactive terminal to build it automatically, or set PHP_HOME',
            ];
        }

        if (!is_file($phpxDir . '/lib/libphpx.' . $ext)) {
            $warnings[] = [
                'warning' => "The `libphpx.{$ext}` is not found",
                'info' => 'Run tpc.php in an interactive terminal to build it automatically, or set PHPX_HOME',
            ];
        }

        return $warnings;
    }

    /**
     * 当前平台是否适合使用 pcntl_fork 并行编译
     */
    public function supportsPcntlParallelCompile(): bool
    {
        return true;
    }

    public function getIntegerLiteralSuffix(): string
    {
        return 'LL';
    }

    /**
     * 规范化路径
     */
    public function normalizePath(string $path): string
    {
        return str_replace('/', $this->getPathSeparator(), $path);
    }

    /**
     * 组合路径
     */
    public function joinPath(string ...$parts): string
    {
        return implode($this->getPathSeparator(), $parts);
    }

    public function removeCommonPrefix(string $short, string $long): string
    {
        $separator = $this->getPathSeparator();
        if ($separator === '\\') {
            $short = str_replace('/', '\\', $short);
            $long = str_replace('/', '\\', $long);
        }

        $short = rtrim($short, $separator);
        $long = rtrim($long, $separator);
        $shortParts = explode($separator, $short);
        $longParts = explode($separator, $long);
        $prefixLen = 0;
        $len = min(count($shortParts), count($longParts));

        for ($i = 0; $i < $len; $i++) {
            if ($shortParts[$i] === $longParts[$i]) {
                $prefixLen++;
            } else {
                break;
            }
        }

        return implode($separator, array_slice($longParts, $prefixLen));
    }

    /**
     * 获取默认的 RPATH 路径列表（仅 macOS 需要）
     * 
     * @param string|null $phpxDir phpx 目录路径
     * @param string|null $phpDir PHP 目录路径
     * @return array RPATH 路径数组
     */
    public function getDefaultRpaths(?string $phpxDir = null, ?string $phpDir = null): array
    {
        // 默认返回空数组，由子类重写
        return [];
    }
}
