<?php

namespace PhpAot\Php\Backend;

use PhpAot\Php\Platform\PlatformBase;
use PhpAot\Php\Platform\Windows;
use PhpAot\Php\Platform\Macos;

/**
 * Clang 编译器后端实现
 */
class Clang extends GccLikeBackend
{
    public function __construct(PlatformBase $platform, string $compilerCommand = 'clang++', ?string $linkerCommand = null)
    {
        parent::__construct($platform, $compilerCommand, $linkerCommand);
    }

    public function getName(): string
    {
        return 'Clang';
    }

    public function getLinkerCommand(): string
    {
        if ($this->linkerCommand !== null) {
            return $this->linkerCommand;
        }

        if ($this->platform instanceof Windows) {
            return 'link';
        }
        return $this->compilerCommand;
    }

    /**
     * Windows 下优先使用 lld-link，找不到时回退到 link.exe
     */
    public static function detectWindowsLinker(): string
    {
        $output = [];
        $returnCode = 0;
        exec('lld-link --version 2>&1', $output, $returnCode);

        if ($returnCode === 0) {
            return 'lld-link';
        }

        $llvmHome = getenv('LLVM_HOME');
        if ($llvmHome && is_dir($llvmHome)) {
            $lldLinkPath = rtrim($llvmHome, '\/') . '\x64\bin\lld-link.exe';
            if (file_exists($lldLinkPath)) {
                exec('"' . $lldLinkPath . '" --version 2>&1', $output, $returnCode);
                if ($returnCode === 0) {
                    $lldDir = dirname($lldLinkPath);
                    putenv("PATH={$lldDir};" . getenv('PATH'));
                    return 'lld-link';
                }
            }
        }

        return 'link';
    }

    // ──── 钩子方法覆盖 ────

    protected function getCompilerPrefixFlags(): string
    {
        if ($this->platform instanceof Windows) {
            return ' -fms-compatibility'
                . ' -fms-compatibility-version=19.40'
                . ' -fdelayed-template-parsing'
                . ' -fms-extensions';
        }
        return '';
    }

    protected function getLinkerOutputFlag(): string
    {
        return $this->platform instanceof Windows ? '/OUT:' : '-o';
    }

    protected function formatSanitizerFlag(string $sanitizer): string
    {
        return '-fsanitize=' . $sanitizer;
    }

    protected function addPICFlag(array $config, string &$cmd): void
    {
        if ($this->platform instanceof Windows) {
            return;
        }
        if ((!empty($config['build_mode']) && $config['build_mode'] === 'ext') || !empty($config['pic'])) {
            $cmd .= ' -fPIC';
        }
    }

    protected function addPlatformLinkFlags(array $config, string &$cmd): void
    {
        if ($this->platform instanceof Windows) {
            if (!empty($config['debug'])) {
                $cmd .= ' /DEBUG';
            }
            if (!empty($config['no_console'])) {
                $cmd .= ' ' . $this->platform->getSubsystemOptions(true);
            }
            $cmd .= ' ' . $this->platform->getCrtConfig();

            if (!empty($config['build_mode']) && $config['build_mode'] === 'ext') {
                $cmd .= ' /DLL';
            }
            return;
        }

        parent::addPlatformLinkFlags($config, $cmd);
    }

    protected function addPlatformFullLinkFlags(array $options, string &$cmd): void
    {
        if ($this->platform instanceof Windows) {
            if (!empty($options['debug'])) {
                $cmd .= ' /DEBUG';
            }
            if (!empty($options['no_console'])) {
                $cmd .= ' ' . $this->platform->getSubsystemOptions(true);
            }
            $cmd .= ' ' . $this->platform->getCrtConfig();
            return;
        }

        parent::addPlatformFullLinkFlags($options, $cmd);
    }
}
