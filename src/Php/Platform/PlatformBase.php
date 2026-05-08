<?php

namespace PhpAot\Php\Platform;

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
