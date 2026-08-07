<?php

namespace TypePhp\Build;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

final readonly class WasiProjectConfig
{
    private function __construct(
        public string $input,
        public string $buildDir,
        public ?string $output,
        public ?string $browserDir,
        public string $profile,
    ) {
    }

    public static function load(
        string $input,
        ?string $cliBuildDir,
        string $workingDirectory,
        string $defaultBuildDir,
        ?string $cliProfile = null,
    ): self {
        $input = self::absolutePath($input, $workingDirectory);
        $realInput = realpath($input);
        if ($realInput === false || !is_file($realInput)) {
            throw new RuntimeException("WASI input does not exist: {$input}");
        }

        $projectDir = dirname($realInput);
        $config = null;
        if (preg_match('/\.ya?ml$/i', $realInput) === 1) {
            $config = Yaml::parseFile($realInput);
            if (!is_array($config)) {
                throw new RuntimeException('WASI project YAML root must be a map');
            }
        }

        $buildDir = $cliBuildDir;
        if ($buildDir === null && is_array($config) && !empty($config['build-dir'])) {
            $buildDir = (string) $config['build-dir'];
            $buildDir = self::absolutePath($buildDir, $projectDir);
        }
        $buildDir ??= $defaultBuildDir;
        $buildDir = self::absolutePath($buildDir, $workingDirectory);

        if (!is_array($config)) {
            return new self($realInput, $buildDir, null, null, self::normalizeProfile($cliProfile ?? 'component'));
        }

        $target = (string) ($config['target-platform'] ?? 'wasm32-wasip2');
        if (!in_array($target, ['wasm32-wasip2', 'wasm32-unknown-wasip2'], true)) {
            throw new RuntimeException('A WASI project must target wasm32-wasip2');
        }

        $mode = strtolower((string) ($config['mode'] ?? $config['build-mode'] ?? $config['type'] ?? 'bin'));
        if (!in_array($mode, ['bin', 'binary', 'cli'], true)) {
            throw new RuntimeException('A WASI project must use bin mode');
        }

        if (!empty($config['output'])) {
            $output = self::absolutePath((string) $config['output'], $projectDir);
            $extension = pathinfo($output, PATHINFO_EXTENSION);
            if ($extension === '') {
                $output .= '.wasm';
            } elseif (strcasecmp($extension, 'wasm') !== 0) {
                throw new RuntimeException('A WASI project output must use the .wasm extension');
            }
        } else {
            $name = trim((string) ($config['name'] ?? 'app'));
            if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
                throw new RuntimeException('A WASI project name must be a non-empty file name');
            }
            $output = $projectDir . DIRECTORY_SEPARATOR . $name . '.wasm';
        }

        $configProfile = 'component';
        if (array_key_exists('wasm', $config)) {
            if (!is_string($config['wasm'])) {
                throw new RuntimeException('The `wasm` project option must be `component` or `browser`');
            }
            $configProfile = $config['wasm'];
        }
        $profile = self::normalizeProfile($cliProfile ?? $configProfile);

        $browserPath = $config['wasm-browser-dir'] ?? null;
        $browserDir = $profile === 'browser' && !empty($browserPath)
            ? self::absolutePath((string) $browserPath, $projectDir)
            : null;

        return new self($realInput, $buildDir, $output, $browserDir, $profile);
    }

    public static function isWasmEnabled(string $path): bool
    {
        if (!is_file($path) || preg_match('/\.ya?ml$/i', $path) !== 1) {
            return false;
        }
        try {
            $config = Yaml::parseFile($path);
        } catch (\Throwable) {
            return false;
        }
        if (!is_array($config) || !array_key_exists('wasm', $config)) {
            return false;
        }
        return true;
    }

    private static function normalizeProfile(string $profile): string
    {
        $profile = strtolower(trim($profile));
        if (!in_array($profile, ['browser', 'component'], true)) {
            throw new RuntimeException("Unsupported WASI output profile `{$profile}`; expected browser or component");
        }
        return $profile;
    }

    private static function absolutePath(string $path, string $baseDirectory): string
    {
        if ($path === '') {
            throw new RuntimeException('WASI project paths must not be empty');
        }
        if ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }
        return rtrim($baseDirectory, '/\\') . DIRECTORY_SEPARATOR . $path;
    }
}
