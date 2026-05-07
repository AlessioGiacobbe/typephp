<?php

namespace PhpAot\Php\Backend;

use PhpAot\Php\Platform\PlatformBase;
use PhpAot\Php\Platform\Windows;
use PhpAot\Php\Platform\Linux;
use PhpAot\Php\Platform\Macos;

/**
 * 编译器工厂类
 * 根据平台自动创建合适的编译器后端
 */
class CompilerFactory
{
    /**
     * 创建默认编译器后端
     */
    public static function create(PlatformBase $platform): CompilerBackend
    {
        if ($platform instanceof Windows) {
            // Windows 默认使用 MSVC
            return new Msvc($platform);
        } elseif ($platform instanceof Linux) {
            // Linux 默认使用 GCC
            return new Gcc($platform);
        } elseif ($platform instanceof Macos) {
            // macOS 默认使用 Clang
            return new Clang($platform);
        } else {
            throw new \RuntimeException("Unsupported platform: " . $platform->getName());
        }
    }

    /**
     * 创建指定类型的编译器后端
     */
    public static function createByName(string $compilerName, PlatformBase $platform): CompilerBackend
    {
        return match (strtolower($compilerName)) {
            'msvc', 'cl' => new Msvc($platform),
            'gcc', 'g++' => new Gcc($platform),
            'clang', 'clang++' => new Clang($platform),
            default => throw new \RuntimeException("Unsupported compiler: {$compilerName}"),
        };
    }

    /**
     * 自动检测并创建编译器和平台
     */
    public static function autoDetect(string $compilerName = ''): array
    {
        // 创建平台
        $platform = \PhpAot\Php\Platform\PlatformFactory::create();
        
        // 创建编译器
        if (!empty($compilerName)) {
            $compiler = self::createByName($compilerName, $platform);
        } else {
            $compiler = self::create($platform);
        }
        
        return [
            'platform' => $platform,
            'compiler' => $compiler,
        ];
    }
}
