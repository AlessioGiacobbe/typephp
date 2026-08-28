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
        if (is_string($phpDir) && $phpDir !== '') {
            $phpDir = rtrim($phpDir, '\/');
            if (!is_dir($phpDir)) {
                throw new \RuntimeException("PHP_HOME is not a directory: {$phpDir}");
            }
            return $phpDir;
        }

        // Ubuntu/PPA 多版本环境下 php8.4 与 php-config8.4 并存，而
        // php-config 可能被 update-alternatives 指向其它版本。优先依据
        // PHP_BINARY 的版本后缀定位版本化 php-config，避免 ABI 错配。
        $versionedConfig = $this->findVersionedPhpConfig(dirname(realpath(PHP_BINARY) ?: PHP_BINARY));
        if ($versionedConfig !== null) {
            $prefix = $this->getPhpConfigValue($versionedConfig, '--prefix');
            if ($prefix !== null && is_dir($prefix)) {
                return rtrim($prefix, '/');
            }
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
            $includes = shell_exec(escapeshellarg($phpConfigPath) . ' --includes 2>/dev/null');
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
        $candidates = [];
        $phpDir = rtrim($phpDir, '/');

        $phpHome = getenv('PHP_HOME');
        if (is_string($phpHome) && $phpHome !== '') {
            $phpHome = rtrim($phpHome, '/');
            $expected = realpath($phpHome) ?: $phpHome;
            $actual = realpath($phpDir) ?: $phpDir;
            if ($actual === $expected) {
                // PHP_HOME is authoritative. Ubuntu/PPA installs several PHP
                // versions under /usr, so prefer php-config8.x over the
                // unversioned php-config selected by update-alternatives.
                $versioned = $this->findVersionedPhpConfig($phpDir . '/bin');
                if ($versioned !== null) {
                    $candidates[] = $versioned;
                }
                $candidate = $phpDir . '/bin/php-config';
                if (is_executable($candidate)) {
                    $candidates[] = $candidate;
                }

                if ($candidates === []) {
                    throw new \RuntimeException(
                        "PHP_HOME does not provide an executable bin/php-config: {$phpDir}"
                    );
                }

                foreach (array_unique($candidates) as $config) {
                    if ($this->phpConfigMatchesCurrentPhp($config)) {
                        return $config;
                    }
                }
                $this->reportPhpConfigVersionMismatch($candidates[0]);
            }
        }

        // Prefer the config belonging to the requested installation. If its
        // unversioned config belongs to another PHP, the version check below
        // will continue with the config beside the running PHP binary.
        $candidate = $phpDir . '/bin/php-config';
        if (is_executable($candidate)) {
            $candidates[] = $candidate;
        }

        $versioned = $this->findVersionedPhpConfig(dirname(realpath(PHP_BINARY) ?: PHP_BINARY));
        if ($versioned !== null) {
            $candidates[] = $versioned;
        }

        // PATH is only a fallback, and its prefix must match the selected PHP.
        $whichResult = trim(shell_exec('which php-config 2>/dev/null'));
        if ($whichResult && is_executable($whichResult)) {
            $prefix = $this->getPhpConfigValue($whichResult, '--prefix');
            $expected = realpath($phpDir) ?: rtrim($phpDir, '/');
            $actual = $prefix === null ? null : (realpath($prefix) ?: rtrim($prefix, '/'));
            if ($actual === $expected) {
                $candidates[] = $whichResult;
            }
        }

        // 依次返回第一个与当前 PHP 主次版本匹配的候选
        foreach (array_unique($candidates) as $config) {
            if ($this->phpConfigMatchesCurrentPhp($config)) {
                return $config;
            }
        }

        // 存在候选但版本均不匹配时给出明确错误
        if ($candidates !== []) {
            $this->reportPhpConfigVersionMismatch($candidates[0]);
        }

        return null;
    }

    /** Find php-config8.x for the PHP version executing the compiler. */
    private function findVersionedPhpConfig(string $binDir): ?string
    {
        $candidate = rtrim($binDir, '/') . '/php-config' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        return is_executable($candidate) ? $candidate : null;
    }

    /**
     * 校验 php-config 的主次版本号是否与当前运行的 PHP 一致。
     */
    private function phpConfigMatchesCurrentPhp(string $phpConfig): bool
    {
        $version = $this->getPhpConfigValue($phpConfig, '--version');
        if ($version === null) {
            return false;
        }
        if (preg_match('/^(\d+\.\d+)\.\d+/', $version, $match)) {
            return $match[1] === PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        }
        return false;
    }

    private function reportPhpConfigVersionMismatch(string $phpConfig): void
    {
        $version = $this->getPhpConfigValue($phpConfig, '--version') ?? 'unknown';
        throw new \RuntimeException(sprintf(
            "The `php-config` (%s) reports PHP %s, but the running PHP is %s. " .
            'Set PHP_HOME to the matching PHP installation.',
            $phpConfig,
            $version,
            PHP_VERSION,
        ));
    }

    protected function getPhpConfigValue(string $phpConfig, string $option): ?string
    {
        $value = shell_exec(escapeshellarg($phpConfig) . ' ' . escapeshellarg($option) . ' 2>/dev/null');
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        return trim($value);
    }

    protected function resolvePhpLibDir(string $phpDir): ?string
    {
        $phpConfig = $this->findPhpConfig($phpDir);
        if ($phpConfig !== null) {
            $libDir = $this->getPhpConfigValue($phpConfig, '--lib-dir');
            if ($libDir !== null && is_dir($libDir)) {
                return rtrim($libDir, '/');
            }
        }

        $libDir = rtrim($phpDir, '/') . '/lib';
        return is_dir($libDir) ? $libDir : null;
    }

    /**
     * 构建 PHP 库路径
     */
    public function buildPhpLibPaths(string $phpDir): array
    {
        $libPath = $this->resolvePhpLibDir($phpDir);
        return $libPath === null ? [] : [$libPath];
    }

    /**
     * 检测 PHP 库文件
     */
    public function detectPhpLibs(string $phpDir): array
    {
        $libPath = $this->resolvePhpLibDir($phpDir);
        if ($libPath === null) {
            throw new \RuntimeException("PHP library directory not found for installation: {$phpDir}");
        }

        $ext = ltrim($this->getSharedLibraryExtension(), '.');
        $embedLib = null;
        $staticLib = null;

        $phpConfig = $this->findPhpConfig($phpDir);
        $configuredEmbed = $phpConfig === null ? null : $this->getPhpConfigValue($phpConfig, '--lib-embed');
        if ($configuredEmbed !== null) {
            $configuredPath = str_starts_with($configuredEmbed, '/')
                ? $configuredEmbed
                : $libPath . '/' . $configuredEmbed;
            if (is_file($configuredPath)) {
                if (str_ends_with($configuredPath, '.a')) {
                    $staticLib = $configuredPath;
                } else {
                    $embedLib = $configuredPath;
                }
            }
        }

        if ($embedLib === null && $staticLib === null) {
            $sharedCandidate = $libPath . '/libphp.' . $ext;
            $staticCandidate = $libPath . '/libphp.a';
            $embedLib = is_file($sharedCandidate) ? $sharedCandidate : null;
            $staticLib = is_file($staticCandidate) ? $staticCandidate : null;
        }

        $hasEmbed = $embedLib !== null;
        $hasStatic = $staticLib !== null;

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
