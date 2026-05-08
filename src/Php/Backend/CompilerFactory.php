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
            return new Msvc($platform, $platform->getDefaultCompiler());
        } elseif ($platform instanceof Linux) {
            // Linux 默认使用 GCC
            return new Gcc($platform, $platform->getDefaultCompiler());
        } elseif ($platform instanceof Macos) {
            // macOS 默认使用 Clang
            return new Clang($platform, $platform->getDefaultCompiler());
        } else {
            throw new \RuntimeException("Unsupported platform: " . $platform->getName());
        }
    }

    /**
     * 根据配置、环境变量和平台默认值解析编译器命令
     */
    public static function detectCompilerName(PlatformBase $platform, string $configuredCompiler = ''): string
    {
        if ($configuredCompiler !== '') {
            return $configuredCompiler;
        }

        $compilerEnv = getenv('PHPX_CC');
        if ($compilerEnv) {
            return $compilerEnv;
        }

        if (!$platform instanceof Windows) {
            $cxxEnv = getenv('CXX');
            if ($cxxEnv) {
                return $cxxEnv;
            }
        }

        return $platform->getDefaultCompiler();
    }

    /**
     * 创建指定类型的编译器后端
     */
    public static function createByName(string $compilerName, PlatformBase $platform): CompilerBackend
    {
        $normalized = self::normalizeCompilerName($compilerName);
        $lowerCommand = strtolower($compilerName);

        if (str_contains($normalized, 'clang') || str_contains($lowerCommand, 'clang')) {
            $linker = $platform instanceof Windows ? Clang::detectWindowsLinker() : null;
            return new Clang($platform, $compilerName, $linker);
        }

        if (
            $normalized === 'gcc' ||
            $normalized === 'g++' ||
            $normalized === 'c++' ||
            str_ends_with($normalized, '-gcc') ||
            str_ends_with($normalized, '-g++') ||
            str_contains($lowerCommand, 'g++') ||
            str_contains($lowerCommand, 'c++') ||
            str_contains($lowerCommand, 'gcc')
        ) {
            return new Gcc($platform, $compilerName);
        }

        if ($normalized === 'msvc' || $normalized === 'cl') {
            if (!$platform instanceof Windows) {
                throw new \RuntimeException("MSVC compiler is only supported on Windows");
            }
            return new Msvc($platform, $compilerName);
        }

        throw new \RuntimeException("Unsupported compiler: {$compilerName}");
    }

    /**
     * 自动检测并创建编译器和平台
     */
    public static function autoDetect(string $compilerName = '', ?PlatformBase $platform = null): array
    {
        // 创建平台
        $platform ??= \PhpAot\Php\Platform\PlatformFactory::create();
        
        // 创建编译器
        $compilerName = self::detectCompilerName($platform, $compilerName);
        $compiler = self::createByName($compilerName, $platform);
        
        return [
            'platform' => $platform,
            'compiler' => $compiler,
        ];
    }

    private static function normalizeCompilerName(string $compilerName): string
    {
        $firstToken = strtok(trim($compilerName), ' ');
        if ($firstToken === false || $firstToken === '') {
            return '';
        }

        $name = basename(str_replace('\\', '/', $firstToken));
        $name = strtolower($name);

        return preg_replace('/\.exe$/', '', $name);
    }
}
