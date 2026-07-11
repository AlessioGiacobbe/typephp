<?php
/**
 * This file is part of TypePHP.
 *
 * Resolves native include, library, linker, and output configuration.
 */

namespace TypePhp\Build;

use TypePhp\Platform\Windows;

trait NativeBuildConfigurationTrait
{
    protected function getIncludePaths(): array
    {
        $platform = $this->getPlatform();
        $includePaths = [
            $this->getPhpxDir() . '/include',
            $this->getBuildDir() . '/include',
            $this->getPhpxDir() . '/src/misc',
        ];

        // 根据平台添加 PHP 包含路径
        if ($platform instanceof Windows) {
            $phpSdkPaths = $platform->buildPhpSdkIncludePaths($this->getPhpDir());
            $includePaths = array_merge($includePaths, $phpSdkPaths);
        } else {
            // Linux/macOS
            $phpPaths = $platform->buildPhpIncludePaths($this->getPhpDir());
            $includePaths = array_merge($includePaths, $phpPaths);
            // 内置 mpdecimal 头文件目录
            $includePaths[] = $this->getPhpxDir() . '/thirdparty/mpdecimal/libmpdec';
            $includePaths[] = $this->getPhpxDir() . '/thirdparty/mpdecimal/libmpdec++';
        }

        return $includePaths;
    }

    /**
     * 解析包含路径
     */
    protected function parseIncludes(): string
    {
        return $this->getPlatform()->getIncludeFlags($this->getIncludePaths());
    }

    protected function getLibraryPaths(): array
    {
        $platform = $this->getPlatform();
        $libraryPaths = [
            $this->getPhpxDir() . '/lib',
        ];

        // 根据平台添加 PHP 库路径
        if ($platform instanceof Windows) {
            $phpLibPaths = $platform->buildPhpSdkLibPaths($this->getPhpDir());
            $libraryPaths = array_merge($libraryPaths, $phpLibPaths);
        } else {
            // Linux/macOS
            $phpLibPaths = $platform->buildPhpLibPaths($this->getPhpDir());
            $libraryPaths = array_merge($libraryPaths, $phpLibPaths);
        }

        return $libraryPaths;
    }

    protected function parseLdflags(): string
    {
        $flags = $this->getPlatform()->getLibraryPathFlags($this->getLibraryPaths());
        
        // 添加用户自定义的 ldflags
        if (!empty($this->ldflags)) {
            $flags .= ' ' . $this->ldflags;
        }
        
        return $flags;
    }

    /**
     * 获取库文件
     */
    protected function getLibraries(): array
    {
        $platform = $this->getPlatform();
        $libraries = [];

        // phpx 库（根据平台使用不同的文件名格式）
        if ($platform instanceof Windows) {
            // Windows: phpx.lib (无 lib 前缀)
            $phpxLibPath = $this->getPhpxDir() . '\\lib\\phpx.lib';
            if (file_exists($phpxLibPath)) {
                $libraries[] = $phpxLibPath;  // 不添加引号，由 getLibraryFlags() 统一处理
            } else {
                $this->error('phpx.lib not found at: ' . $phpxLibPath);
            }
        } else {
            // Linux/macOS: libphpx.so 或 libphpx.a
            $sharedLibExt = $platform->getSharedLibraryExtension();
            // getSharedLibraryExtension() 返回的值可能带点或不带点，需要统一处理
            $extWithoutDot = ltrim($sharedLibExt, '.');
            $phpxLibPath = $this->getPhpxDir() . '/lib/libphpx.' . $extWithoutDot;
            if (file_exists($phpxLibPath)) {
                $libraries[] = $phpxLibPath;
            } else {
                // 尝试静态库
                $phpxStaticPath = $this->getPhpxDir() . '/lib/libphpx.a';
                if (file_exists($phpxStaticPath)) {
                    $libraries[] = $phpxStaticPath;
                } else {
                    $this->error('libphpx library not found');
                }
            }
        }

        // extension 和 bin 模式都需要链接 PHP 库
        if ($platform instanceof Windows) {
            // Windows: 根据构建模式选择不同的库
            if ($this->isBuildModeEmbed()) {
                // bin 模式：需要同时链接 php8ts.lib 和 php8embed.lib
                // 注意：php8ts.lib 必须在 php8embed.lib 之前，因为 embed 依赖 core
                // php8ts.lib 提供 PHP 核心全局符号（executor_globals, compiler_globals, sapi_globals）
                if (!empty($this->windowsPhpCoreLib)) {
                    $libraries[] = $this->windowsPhpCoreLib;  // 不添加引号
                }
                // php8embed.lib 提供嵌入 API
                if (!empty($this->windowsPhpEmbedLib)) {
                    $libraries[] = $this->windowsPhpEmbedLib;  // 不添加引号
                }
            } else {
                // ext 模式：只使用 php8ts.lib 或 php8.lib（PHP 扩展）
                if (!empty($this->windowsPhpCoreLib)) {
                    $libraries[] = $this->windowsPhpCoreLib;  // 不添加引号
                }
            }
            
            // 添加 Windows API 库（Win32 GUI 程序需要）
            $libraries[] = 'user32.lib';   // Windows UI 函数（CreateWindow, MessageBox 等）
            $libraries[] = 'gdi32.lib';    // GDI 图形函数
            $libraries[] = 'kernel32.lib'; // 核心 Windows API
            $libraries[] = 'gmp.lib';
            $libraries[] = 'gmpxx.lib';
            $libraries[] = 'mpfr.lib';
            $libraries[] = 'libmpdec-4.0.1.dll.lib';
            $libraries[] = 'libmpdec++-4.0.1.dll.lib';
        } else {
            // Linux/macOS: extension 和 bin 模式都需要添加 php 库
            $libraries[] = 'php';
            $libraries[] = 'gmp';
            $libraries[] = 'gmpxx';
            $libraries[] = 'mpfr';
        }

        return $libraries;
    }

    /**
     * 解析库文件
     */
    protected function parseLibs(): string
    {
        return $this->getPlatform()->getLibraryFlags($this->getLibraries());
    }

}
