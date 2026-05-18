<?php

namespace PhpAot\Php\Backend;

use PhpAot\Php\Platform\PlatformBase;

/**
 * 编译器后端抽象基类
 * 定义所有编译器必须实现的接口
 */
abstract class CompilerBackend
{
    /**
     * 平台实例
     */
    protected PlatformBase $platform;

    public function __construct(PlatformBase $platform)
    {
        $this->platform = $platform;
    }

    /**
     * 获取编译器名称
     */
    abstract public function getName(): string;

    /**
     * 获取编译器命令
     */
    abstract public function getCompilerCommand(): string;

    /**
     * 获取链接器命令
     */
    abstract public function getLinkerCommand(): string;

    /**
     * 编译单个文件
     */
    abstract public function compileFile(
        string $sourceFile,
        string $outputFile,
        array $includePaths = [],
        array $defines = [],
        array $flags = []
    ): string;

    /**
     * 链接目标文件
     */
    abstract public function linkObjects(
        array $objectFiles,
        string $outputFile,
        array $libraryPaths = [],
        array $libraries = [],
        array $flags = []
    ): string;

    /**
     * 构建完整的编译命令
     */
    abstract public function buildCompileCommand(
        string $sourceFile,
        string $outputFile,
        array $options = []
    ): string;

    /**
     * 构建 C 文件的编译命令（不包含 C++ 特定选项）
     */
    abstract public function buildCCompileCommand(
        string $sourceFile,
        string $outputFile,
        array $options = []
    ): string;

    /**
     * 构建原生源文件的编译命令（汇编/Objective-C 等，使用 -x 指定语言）
     *
     * @param string $language GCC/Clang 语言标识（assembler, objective-c, objective-c++ 等）
     */
    abstract public function buildNativeCompileCommand(
        string $sourceFile,
        string $outputFile,
        array $options = [],
        string $language = ''
    ): string;

    /**
     * 构建完整的链接命令
     */
    abstract public function buildLinkCommand(
        array $objectFiles,
        string $outputFile,
        array $options = []
    ): string;

    /**
     * 构建编译选项（不含文件路径）
     * @param array $config 编译配置
     *   - optimize: 优化级别 (0-3)
     *   - debug_info: 是否生成调试信息
     *   - sanitize: sanitizer 类型 (address, undefined, etc.)
     *   - cpp_std: C++ 标准版本
     *   - is_zts: 是否为 ZTS 模式
     *   - build_mode: 构建模式 ('bin' or 'ext')
     *   - enable_profiler: 是否启用性能分析
     *   - suppressed_warnings: 需要屏蔽的警告代码数组
     *   - cxxflags: 用户自定义编译标志
     */
    abstract public function buildCompileOptions(array $config = []): string;

    /**
     * 构建链接选项（不含文件路径）
     * @param array $config 链接配置
     *   - debug_info: 是否生成调试信息
     *   - no_console: 是否隐藏控制台窗口
     *   - build_mode: 构建模式 ('bin' or 'ext')
     *   - sanitize: sanitizer 类型
     */
    abstract public function buildLinkOptions(array $config = []): string;

    /**
     * 获取平台实例
     */
    public function getPlatform(): PlatformBase
    {
        return $this->platform;
    }

    /**
     * 格式化包含路径
     */
    protected function formatIncludePaths(array $includePaths): string
    {
        return $this->platform->getIncludeFlags($includePaths);
    }

    /**
     * 格式化库路径
     */
    protected function formatLibraryPaths(array $libraryPaths): string
    {
        return $this->platform->getLibraryPathFlags($libraryPaths);
    }

    /**
     * 格式化库文件
     */
    protected function formatLibraries(array $libraries): string
    {
        return $this->platform->getLibraryFlags($libraries);
    }
}
