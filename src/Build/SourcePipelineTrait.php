<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Build;

use TypePhp\Backend\CompilerFactory;
use TypePhp\Exception\SyntaxError;
use TypePhp\Exception\Unsupported;
use TypePhp\Installer\LibPhpInstaller;
use TypePhp\Installer\LibPhpxInstaller;
use TypePhp\Platform\Linux;
use TypePhp\Platform\Windows;

trait SourcePipelineTrait
{
    public function addFiles(array $files): void
    {
        $this->sourceDirs = array_merge($this->sourceDirs, $files);
    }

    public function getFiles(string $path): array
    {
        $this->applyPhpVersionCommandLineArgument();
        $realpath = realpath($path);
        if ($realpath === false) {
            $this->error("path not exists: {$path}");
        }
        $path = $realpath;

        if (is_dir($path)) {
            // 目录模式：不解析 YAML
            $list = $this->getFilesFromDir($path);
            $targetName = basename($path);
            $this->setTargetName($targetName);
            $this->sourceDirs[] = $path;
        } else {
            $ext = pathinfo($path, PATHINFO_EXTENSION);
            if ($ext === 'yml' || $ext === 'yaml') {
                // YAML 配置模式：先解析 YAML
                $list = $this->parseProjectYaml($path);
            } elseif ($ext === 'php') {
                // 单文件模式：不解析 YAML
                $list = [$path];
                $targetName = FileScanner::getFileName($path);
                $this->setTargetName($targetName);
                $this->sourceDirs[] = dirname($path);
            } else {
                $this->error('Unsupported file type: ' . $path);
            }
        }

        // 在所有配置加载完成后，应用命令行参数（确保优先级最高）
        $this->applyCommandLineArguments();

        // The generated public import stub is an output artifact, not an input
        // of the library that produced it. Exclude a previous build's copy when
        // a project scans its output directory recursively.
        if ($this->isBuildModeLib()) {
            $generatedStub = realpath($this->getLibraryImportStubFile());
            if ($generatedStub !== false) {
                $list = array_values(array_filter(
                    $list,
                    static fn(string $file): bool => realpath($file) !== $generatedStub,
                ));
            }
        }

        return $this->filterIgnoredFiles($list);
    }

    public function prepare(string $path): array
    {
        $files = $this->getFiles($path);

        if ($this->isBuildModeEmbed() && $this->getPlatform() instanceof Linux) {
            try {
                $phpDir = (new LibPhpInstaller())->ensure($this->getPhpDir()) ?? $this->getPhpDir();
            } catch (\Throwable $e) {
                $this->error('Unable to install libphp.so: ' . $e->getMessage());
            }
        } else {
            $phpDir = $this->getPhpDir();
        }

        if ($this->getPlatform() instanceof Linux) {
            try {
                (new LibPhpxInstaller())->ensure($this->getPhpxDir(), $phpDir);
            } catch (\Throwable $e) {
                $this->error('Unable to build libphpx.so: ' . $e->getMessage());
            }
        }

        $this->validateCompilerToolchain();

        // shell_exec 和 define 已通过 php::fn:: 直接调用，无需动态符号表

        // Windows 的所有构建模式都依赖 PHPX 导入库和运行库。
        // 其他平台仅在嵌入式构建模式下执行现有检查。
        if ($this->isBuildModeEmbed() || $this->getPlatform() instanceof Windows) {
            foreach ($this->getPlatform()->getBuildLibraryWarnings($this->getPhpDir(), $this->getPhpxDir(), $this->buildMode) as $message) {
                if (!empty($message['error'])) {
                    $detail = $message['error'];
                    if (!empty($message['info'])) {
                        $detail .= "\n" . $message['info'];
                    }
                    $this->error($detail);
                }
                $this->climate->warning($message['warning']);
                if (!empty($message['info'])) {
                    $this->climate->info($message['info']);
                }
            }
        }

        $files = $this->filterIgnoredFiles($files);
        // 分析 PHP 文件，预处理
        foreach ($files as $k => $file) {
            if (FileScanner::isPhpFile($file)) {
                try {
                    $this->prepareFile($file);
                } catch (Unsupported $e) {
                    $this->output(' unsupported syntax: ' . $e->getMessage() . "\n" . ' skip: ' . $file . "\n", 'error');
                    unset($files[$k]);
                } catch (SyntaxError $e) {
                    $this->output(' syntax error: ' . $e->getMessage() . "\n" . ' skip: ' . $file . "\n", 'error');
                    unset($files[$k]);
                }
            }
        }
        $files = $this->getSortedFiles($files);
        return $files;
    }

    protected function validateCompilerToolchain(): void
    {
        $backend = $this->getCompilerBackend();
        $compilerCommand = $backend->getCompilerCommand();
        if (!CompilerFactory::isCommandExecutable($compilerCommand)) {
            $program = CompilerFactory::getCommandProgram($compilerCommand);
            $this->error(
                "C/C++ compiler executable not found: {$program}\n" .
                "Configured compiler command: {$compilerCommand}\n" .
                "Install a supported compiler or set `cpp-compiler` in project.yml / PHPX_CC / CXX."
            );
        }

        $linkerCommand = $backend->getLinkerCommand();
        if ($linkerCommand !== $compilerCommand && !CompilerFactory::isCommandExecutable($linkerCommand)) {
            $program = CompilerFactory::getCommandProgram($linkerCommand);
            $this->error(
                "Linker executable not found: {$program}\n" .
                "Configured linker command: {$linkerCommand}\n" .
                "Install the required linker or update compiler configuration."
            );
        }
    }

    protected function shouldIgnoreFile(string $file): bool
    {
        foreach ($this->ignorePaths as $ignorePath) {
            if ($file === $ignorePath) {
                return true;
            }
            if (is_dir($ignorePath) && str_starts_with($file, rtrim($ignorePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                return true;
            }
        }

        return false;
    }

    protected function filterIgnoredFiles(array $files): array
    {
        if (empty($this->ignorePaths)) {
            return $files;
        }

        $filteredFiles = [];
        foreach ($files as $file) {
            if (!$this->shouldIgnoreFile($file)) {
                $filteredFiles[] = $file;
            }
        }

        return $filteredFiles;
    }

    public function convert(array $files): array
    {
        $sourceFiles = [];
        // 生成 C++ 文件
        foreach ($files as $k => $file) {
            try {
                if (FileScanner::isPhpFile($file)) {
                    $cppFile = $this->convertFile($file);
                } elseif (FileScanner::isNativeSourceFile($file)) {
                    $cppFile = $file;
                } else {
                    continue;
                }
                $sourceFiles[] = $cppFile;
            } catch (Unsupported $e) {
                echo ' unsupported syntax: ' . $e->getMessage() . "\n";
                echo ' skip: ' . $file . "\n";
                unset($files[$k]);
            }
        }

        if (empty($sourceFiles)) {
            $this->stop('No valid source file found');
        }

        if ($this->isBuildModeLib()) {
            $this->genLibraryImportStub($files);
        }

        // 生成构建期内部头文件：函数声明、运行时数据声明
        $this->genFunctionDeclarations($this->getIncludeDir() . "/php_{$this->targetName}_func_decl.h");
        $this->genDataDeclarations($this->getIncludeDir() . "/php_{$this->targetName}_data_decl.h");
        // 生成扩展模块源文件
        $sourceFiles[] = $this->genExtension();

        return $sourceFiles;
    }
}
