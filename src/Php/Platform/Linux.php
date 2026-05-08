<?php

namespace PhpAot\Php\Platform;

/**
 * Linux 平台实现
 */
class Linux extends PlatformBase
{
    public function getName(): string
    {
        return 'Linux';
    }

    public function isCurrent(): bool
    {
        return PHP_OS === 'Linux';
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
            // 移除 lib 前缀和 .a/.so 后缀
            $libName = basename($lib);
            if (str_starts_with($libName, 'lib')) {
                $libName = substr($libName, 3);
            }
            $libName = preg_replace('/\.(a|so)$/', '', $libName);
            
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
        return '.so';
    }

    public function getPathSeparator(): string
    {
        return '/';
    }

    public function getDefaultCompiler(): string
    {
        return 'g++';
    }

    public function getPhpDir(): string
    {
        $phpDir = getenv('PHP_HOME');
        if ($phpDir && is_dir($phpDir)) {
            return rtrim($phpDir, '\/');
        }

        $phpDir = shell_exec('php-config --prefix 2>/dev/null');
        if (!empty($phpDir)) {
            return trim($phpDir);
        }

        $phpExe = trim(shell_exec('which php 2>/dev/null'));
        if ($phpExe && file_exists($phpExe)) {
            $phpDir = dirname(dirname($phpExe));
            if (is_dir($phpDir)) {
                return $phpDir;
            }
        }

        throw new \RuntimeException('The `php-config` is not found. Please install PHP development package or set PHP_HOME environment variable');
    }

    public function getIntegerLiteralSuffix(): string
    {
        return 'L';
    }

    /**
     * 获取 RPATH 选项
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
        return '-shared';
    }

    /**
     * 构建 PHP 包含路径（使用 php-config 动态获取）
     */
    public function buildPhpIncludePaths(string $phpDir): array
    {
        // 优先使用 php-config 获取包含路径
        $phpConfigPath = $this->findPhpConfig($phpDir);
        if ($phpConfigPath) {
            $includes = shell_exec("{$phpConfigPath} --includes 2>/dev/null");
            if ($includes) {
                // 解析 -I/path 格式的路径
                preg_match_all('/-I([^\s]+)/', $includes, $matches);
                if (!empty($matches[1])) {
                    // 过滤不存在的路径并返回
                    return array_filter($matches[1], 'is_dir');
                }
            }
        }
        
        // 回退到硬编码路径（兼容旧版本）
        $paths = [
            $phpDir . '/include/php',
            $phpDir . '/include/php/main',
            $phpDir . '/include/php/TSRM',
            $phpDir . '/include/php/Zend',
            $phpDir . '/include/php/ext',
        ];

        // 过滤不存在的路径
        return array_filter($paths, 'is_dir');
    }
    
    /**
     * 查找 php-config 可执行文件
     */
    private function findPhpConfig(string $phpDir): ?string
    {
        // 优先使用 PHP_DIR 指定的路径
        $candidate = $phpDir . '/bin/php-config';
        if (is_executable($candidate)) {
            return $candidate;
        }
        
        // 回退到 PATH 中查找（通过 which 命令）
        $whichResult = trim(shell_exec('which php-config 2>/dev/null'));
        if ($whichResult && is_executable($whichResult)) {
            return $whichResult;
        }
        
        return null;
    }

    /**
     * 构建 PHP 库路径
     */
    public function buildPhpLibPaths(string $phpDir): array
    {
        $paths = [];
        
        $libPath = $phpDir . '/lib';
        if (is_dir($libPath)) {
            $paths[] = $libPath;
        }

        return $paths;
    }

    /**
     * 检测 PHP 库文件
     */
    public function detectPhpLibs(string $phpDir): array
    {
        $libPath = $phpDir . '/lib';
        
        if (!is_dir($libPath)) {
            throw new \RuntimeException("PHP lib directory not found: {$libPath}");
        }

        $embedLib = $libPath . '/libphp.so';
        $staticLib = $libPath . '/libphp.a';

        $hasEmbed = file_exists($embedLib);
        $hasStatic = file_exists($staticLib);

        if (!$hasEmbed && !$hasStatic) {
            throw new \RuntimeException('Neither libphp.so nor libphp.a found');
        }

        return [
            'embed' => $hasEmbed ? $embedLib : null,
            'static' => $hasStatic ? $staticLib : null,
            'is_shared' => $hasEmbed,
        ];
    }
}
