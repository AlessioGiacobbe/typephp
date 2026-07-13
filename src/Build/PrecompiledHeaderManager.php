<?php

namespace TypePhp\Build;

use TypePhp\Backend\CompilerBackend;

final readonly class PrecompiledHeaderManager
{
    public function __construct(
        private CompilerBackend $backend,
        private NativeBuilder $builder,
    ) {
    }

    /**
     * @param list<string> $headers
     * @param list<string> $dependencyDirectories
     * @return array{header: string, artifact: string, cached: bool, command: string}
     */
    public function prepare(
        array $headers,
        array $dependencyDirectories,
        string $cacheDirectory,
        CompileOptions $options,
    ): array {
        if (!$this->backend->supportsPrecompiledHeaders()) {
            throw new \LogicException($this->backend->getName() . ' does not support precompiled headers');
        }

        $fingerprint = $this->buildFingerprint($headers, $dependencyDirectories, $options);
        $directory = rtrim($cacheDirectory, '/\\') . DIRECTORY_SEPARATOR . $fingerprint;
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create precompiled header cache directory: ' . $directory);
        }

        $headerFile = $directory . DIRECTORY_SEPARATOR . 'typephp_pch.hpp';
        $artifact = $this->backend->getPrecompiledHeaderArtifact($headerFile);
        $source = "#pragma once\n";
        foreach ($headers as $header) {
            $source .= '#include <' . $header . ">\n";
        }
        if (!is_file($headerFile) || file_get_contents($headerFile) !== $source) {
            if (file_put_contents($headerFile, $source) === false) {
                throw new \RuntimeException('Cannot write precompiled header: ' . $headerFile);
            }
        }

        if (is_file($artifact)) {
            return ['header' => $headerFile, 'artifact' => $artifact, 'cached' => true, 'command' => ''];
        }

        $result = $this->builder->compile($headerFile, $artifact, $options, 'c++-header', true);
        if ($result['status'] !== 0 || !is_file($artifact)) {
            $message = implode(PHP_EOL, $result['output']);
            throw new \RuntimeException('Failed to build PHPX precompiled header' . ($message === '' ? '' : ': ' . $message));
        }

        return ['header' => $headerFile, 'artifact' => $artifact, 'cached' => false, 'command' => $result['command']];
    }

    /** @param list<string> $headers @param list<string> $dependencyDirectories */
    private function buildFingerprint(array $headers, array $dependencyDirectories, CompileOptions $options): string
    {
        $compilerVersion = [];
        exec(escapeshellcmd($this->backend->getCompilerCommand()) . ' --version 2>&1', $compilerVersion);
        $context = hash_init('sha256');
        hash_update($context, $this->backend::class . "\0" . implode("\n", $compilerVersion) . "\0");
        $optionValues = $options->toArray();
        // prof_output only affects code when profiling is enabled. Keeping a
        // target-specific inactive filename here would defeat PCH reuse across
        // projects with otherwise identical native build configurations.
        if (empty($optionValues['enable_profiler'])) {
            unset($optionValues['prof_output']);
        }
        unset($optionValues['precompiled_header']);
        hash_update($context, serialize($optionValues) . "\0" . implode("\0", $headers));

        $files = [];
        foreach ($dependencyDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || preg_match('/\.(?:h|hh|hpp|hxx|inc)$/i', $file->getFilename()) !== 1) {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            hash_update($context, $file . "\0");
            if (!hash_update_file($context, $file)) {
                throw new \RuntimeException('Cannot fingerprint precompiled-header dependency: ' . $file);
            }
            hash_update($context, "\0");
        }

        return substr(hash_final($context), 0, 24);
    }
}
