<?php

namespace PhpAot\Php\Backend;

use PhpAot\Php\Platform\PlatformBase;

/**
 * GCC/Clang 共享后端基类
 * 包含 Unix-like 编译器（GCC、Clang）的通用命令行构建逻辑。
 * 子类只需覆盖平台差异的钩子方法。
 */
abstract class GccLikeBackend extends CompilerBackend
{
    protected string $compilerCommand;
    protected ?string $linkerCommand;

    public function __construct(PlatformBase $platform, string $compilerCommand, ?string $linkerCommand = null)
    {
        parent::__construct($platform);
        $this->compilerCommand = $compilerCommand;
        $this->linkerCommand = $linkerCommand;
    }

    public function getCompilerCommand(): string
    {
        return $this->compilerCommand;
    }

    // ──── 钩子方法（子类覆盖点） ────

    /** 编译器特定的前缀标志（如 MSVC 兼容模式） */
    protected function getCompilerPrefixFlags(): string
    {
        return '';
    }

    /** 链接器输出标志（-o vs /OUT:） */
    protected function getLinkerOutputFlag(): string
    {
        return '-o';
    }

    /** 格式化 sanitizer 标志 */
    protected function formatSanitizerFlag(string $sanitizer): string
    {
        return match ($sanitizer) {
            'address', 'addr' => '-fsanitize=address',
            'undefined', 'undef' => '-fsanitize=undefined',
            default => '-fsanitize=' . $sanitizer,
        };
    }

    /** 添加 PIC 标志 */
    protected function addPICFlag(array $config, string &$cmd): void
    {
        if ((!empty($config['build_mode']) && $config['build_mode'] === 'ext') || !empty($config['pic'])) {
            $cmd .= ' -fPIC';
        }
    }

    /** 添加平台特定的链接选项 */
    protected function addPlatformLinkFlags(array $config, string &$cmd): void
    {
        if ((!empty($config['build_mode']) && $config['build_mode'] === 'ext') || !empty($config['shared'])) {
            $cmd .= ' ' . $this->platform->getSharedLinkFlag();

            if ($this->platform instanceof \PhpAot\Php\Platform\Macos && !empty($config['install_name'])) {
                $cmd .= ' ' . $this->platform->getCurrentInstallNameOption($config['install_name']);
            }
        }

        if (!empty($config['rpath'])) {
            foreach ($config['rpath'] as $path) {
                $cmd .= ' -Wl,-rpath,' . escapeshellarg($path);
            }
        }
    }

    /** 添加平台特定的完整链接选项 */
    protected function addPlatformFullLinkFlags(array $options, string &$cmd): void
    {
        if (!empty($options['shared'])) {
            $cmd .= ' ' . $this->platform->getSharedLinkFlag();
        }

        if (!empty($options['rpath'])) {
            $cmd .= ' ' . $this->platform->getRpathOptions($options['rpath']);
        }
    }

    // ──── 抽象方法实现 ────

    public function compileFile(
        string $sourceFile,
        string $outputFile,
        array $includePaths = [],
        array $defines = [],
        array $flags = []
    ): string {
        $cmd = $this->getCompilerCommand();
        $cmd .= $this->getCompilerPrefixFlags();
        $cmd .= ' -c ' . escapeshellarg($sourceFile);
        $cmd .= ' -o ' . escapeshellarg($outputFile);

        if (!empty($includePaths)) {
            $cmd .= ' ' . $this->formatIncludePaths($includePaths);
        }

        foreach ($defines as $define) {
            $cmd .= ' -D' . $define;
        }

        if (!empty($flags)) {
            $cmd .= ' ' . implode(' ', $flags);
        }

        return $cmd;
    }

    public function linkObjects(
        array $objectFiles,
        string $outputFile,
        array $libraryPaths = [],
        array $libraries = [],
        array $flags = []
    ): string {
        $cmd = $this->getLinkerCommand();
        $cmd .= ' ' . implode(' ', array_map('escapeshellarg', $objectFiles));
        $cmd .= ' ' . $this->getLinkerOutputFlag() . ' ' . escapeshellarg($outputFile);

        if (!empty($libraryPaths)) {
            $cmd .= ' ' . $this->formatLibraryPaths($libraryPaths);
        }

        if (!empty($libraries)) {
            $cmd .= ' ' . $this->formatLibraries($libraries);
        }

        if (!empty($flags)) {
            $cmd .= ' ' . implode(' ', $flags);
        }

        return $cmd;
    }

    public function buildCompileCommand(string $sourceFile, string $outputFile, array $options = []): string
    {
        $cmd = $this->getCompilerCommand();
        $cmd .= $this->getCompilerPrefixFlags();
        $cmd .= ' -c';
        $cmd .= ' ' . escapeshellarg($sourceFile);
        $cmd .= ' -o ' . escapeshellarg($outputFile);

        if (!empty($options['include_paths'])) {
            $cmd .= ' ' . $this->formatIncludePaths($options['include_paths']);
        }

        $cmd .= $this->buildCompileOptions($options);

        return $cmd;
    }

    public function buildCCompileCommand(string $sourceFile, string $outputFile, array $options = []): string
    {
        $cmd = $this->getCompilerCommand();
        $cmd .= $this->getCompilerPrefixFlags();
        $cmd .= ' -c';
        $cmd .= ' -x c';
        $cmd .= ' ' . escapeshellarg($sourceFile);
        $cmd .= ' -o ' . escapeshellarg($outputFile);

        if (!empty($options['include_paths'])) {
            $cmd .= ' ' . $this->formatIncludePaths($options['include_paths']);
        }

        $optimizeLevel = $options['optimize'] ?? 0;
        if (!empty($options['debug'])) {
            $cmd .= ' -O0 -g';
        } else {
            $cmd .= ' -O' . $optimizeLevel;
        }

        $cmd .= ' -Wall';

        return $cmd;
    }

    public function buildNativeCompileCommand(string $sourceFile, string $outputFile, array $options = [], string $language = ''): string
    {
        $cmd = $this->getCompilerCommand();
        $cmd .= $this->getCompilerPrefixFlags();
        $cmd .= ' -c';
        if ($language !== '') {
            $cmd .= ' -x ' . $language;
        }
        $cmd .= ' ' . escapeshellarg($sourceFile);
        $cmd .= ' -o ' . escapeshellarg($outputFile);

        if (!empty($options['include_paths'])) {
            $cmd .= ' ' . $this->formatIncludePaths($options['include_paths']);
        }

        $cmd .= $this->buildCompileOptions($options);

        return $cmd;
    }

    public function buildLinkCommand(array $objectFiles, string $outputFile, array $options = []): string
    {
        $cmd = $this->getLinkerCommand();
        $cmd .= ' ' . implode(' ', array_map('escapeshellarg', $objectFiles));
        $cmd .= ' ' . $this->getLinkerOutputFlag() . ' ' . escapeshellarg($outputFile);

        if (!empty($options['library_paths'])) {
            $cmd .= ' ' . $this->formatLibraryPaths($options['library_paths']);
        }

        if (!empty($options['ldflags'])) {
            $cmd .= ' ' . $options['ldflags'];
        }

        $cmd .= $this->buildLinkOptions($options);

        if (!empty($options['libraries'])) {
            $cmd .= ' ' . $this->formatLibraries($options['libraries']);
        }

        return $cmd;
    }

    public function buildCompileOptions(array $config = []): string
    {
        $cmd = '';

        $cmd .= $this->getCompilerPrefixFlags();

        if (!empty($config['sanitize'])) {
            $cmd .= ' ' . $this->formatSanitizerFlag($config['sanitize']);
        }

        if (!empty($config['debug'])) {
            $cmd .= ' -O0 -g';
        } else {
            $optimizeLevel = $config['optimize'] ?? 2;
            $cmd .= ' -O' . $optimizeLevel;
        }

        $cmd .= ' -Wall';

        if (!empty($config['cpp_std'])) {
            $cmd .= ' -std=' . $config['cpp_std'];
        }

        if (!empty($config['march'])) {
            $cmd .= ' -march=' . $config['march'];
        }

        if (!empty($config['target_platform'])) {
            $cmd .= ' --target=' . $config['target_platform'];
        }

        $this->addPICFlag($config, $cmd);

        if (!empty($config['enable_profiler'])) {
            $cmd .= ' -DPPROF_ON=1';
            if (!empty($config['prof_output'])) {
                $cmd .= ' -DPROF_OUTPUT_FILE=\'"' . $config['prof_output'] . '"\'';
            }
        }

        if (!empty($config['cxxflags'])) {
            $cmd .= ' ' . $config['cxxflags'];
        }

        if (!empty($config['user_defines'])) {
            foreach ($config['user_defines'] as $define) {
                $cmd .= ' -D' . $define;
            }
        }

        if (!empty($config['lto'])) {
            $cmd .= ' -flto';
        }

        return $cmd;
    }

    public function buildLinkOptions(array $config = []): string
    {
        $cmd = '';

        $this->addPlatformLinkFlags($config, $cmd);

        if (!empty($config['sanitize'])) {
            $cmd .= ' ' . $this->formatSanitizerFlag($config['sanitize']);
        }

        if (!empty($config['target_platform'])) {
            $cmd .= ' --target=' . $config['target_platform'];
        }

        if (!empty($config['lto'])) {
            $cmd .= ' -flto';
        }

        return $cmd;
    }

    public function buildFullCompileOptions(array $options = []): string
    {
        $cmd = '';

        $cmd .= $this->getCompilerPrefixFlags();

        if (!empty($options['debug'])) {
            $cmd .= ' -O0 -g';
        } else {
            $optimizeLevel = $options['optimize'] ?? 2;
            $cmd .= ' -O' . $optimizeLevel;
        }

        $cmd .= ' -Wall';

        if (!empty($options['cpp_std'])) {
            $cmd .= ' -std=' . $options['cpp_std'];
        }

        if (!empty($options['march'])) {
            $cmd .= ' -march=' . $options['march'];
        }

        if (!empty($options['sanitize'])) {
            $cmd .= ' ' . $this->formatSanitizerFlag($options['sanitize']);
        }

        if (!empty($options['pic'])) {
            $cmd .= ' -fPIC';
        }

        return $cmd;
    }

    public function buildFullLinkOptions(array $options = []): string
    {
        $cmd = '';

        $this->addPlatformFullLinkFlags($options, $cmd);

        if (!empty($options['sanitize'])) {
            $cmd .= ' -fsanitize=' . $options['sanitize'];
        }

        return $cmd;
    }
}
