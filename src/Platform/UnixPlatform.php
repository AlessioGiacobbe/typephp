<?php

namespace TypePhp\Platform;

/**
 * Unix-like 平台基类（Linux, macOS）
 * 包含 GCC/Clang 通用标志语法的共享实现
 */
abstract class UnixPlatform extends PlatformBase
{
    public function getSharedLinkFlag(): string
    {
        return '-shared';
    }

    public function getSubsystemOptions(bool $noConsole): string
    {
        return '';
    }

    public function getCrtConfig(): string
    {
        return '';
    }

    public function getTargetExtension(string $buildMode): string
    {
        if ($buildMode === 'lib') {
            return $this->getSharedLibraryExtension();
        }

        return parent::getTargetExtension($buildMode);
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

        $ext = ltrim($this->getSharedLibraryExtension(), '.');
        $flags = [];
        foreach ($libraries as $lib) {
            $libName = basename($lib);
            if (str_starts_with($libName, 'lib')) {
                $libName = substr($libName, 3);
            }
            $libName = preg_replace('/\.(a|' . $ext . ')$/', '', $libName);

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

    public function getPathSeparator(): string
    {
        return '/';
    }

    public function getPhpDir(): string
    {
        $phpDir = getenv('PHP_HOME');
        if ($phpDir && is_dir($phpDir)) {
            return rtrim($phpDir, '\/');
        }

        // Composer executes tpc.php with an already selected PHP binary. Use
        // that installation before consulting an unrelated php-config from
        // PATH, which may point at another PHP minor version or ABI.
        $runningPhp = realpath(PHP_BINARY) ?: PHP_BINARY;
        $runningPhpDir = dirname(dirname($runningPhp));
        if (is_executable($runningPhpDir . '/bin/php-config')) {
            return $runningPhpDir;
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
     * 构建 PHP 包含路径（使用 php-config 动态获取）
     */
    public function buildPhpIncludePaths(string $phpDir): array
    {
        $phpConfigPath = $this->findPhpConfig($phpDir);
        if ($phpConfigPath) {
            $includes = shell_exec("{$phpConfigPath} --includes 2>/dev/null");
            if ($includes) {
                preg_match_all('/-I([^\s]+)/', $includes, $matches);
                if (!empty($matches[1])) {
                    $includePaths = [];
                    foreach ($matches[1] as $path) {
                        if (is_dir($path)) {
                            $includePaths[] = $path;
                        }
                    }
                    return $includePaths;
                }
            }
        }

        $paths = [
            $phpDir . '/include/php',
            $phpDir . '/include/php/main',
            $phpDir . '/include/php/TSRM',
            $phpDir . '/include/php/Zend',
            $phpDir . '/include/php/ext',
        ];

        $includePaths = [];
        foreach ($paths as $path) {
            if (is_dir($path)) {
                $includePaths[] = $path;
            }
        }

        return $includePaths;
    }

    /**
     * 查找 php-config 可执行文件
     */
    protected function findPhpConfig(string $phpDir): ?string
    {
        $candidate = $phpDir . '/bin/php-config';
        if (is_executable($candidate)) {
            return $candidate;
        }

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

        $ext = ltrim($this->getSharedLibraryExtension(), '.');
        $embedLib = $libPath . '/libphp.' . $ext;
        $staticLib = $libPath . '/libphp.a';

        $hasEmbed = file_exists($embedLib);
        $hasStatic = file_exists($staticLib);

        if (!$hasEmbed && !$hasStatic) {
            throw new \RuntimeException("Neither libphp.{$ext} nor libphp.a found");
        }

        return [
            'embed' => $hasEmbed ? $embedLib : null,
            'static' => $hasStatic ? $staticLib : null,
            'is_shared' => $hasEmbed,
        ];
    }
}
