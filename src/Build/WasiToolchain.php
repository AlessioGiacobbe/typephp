<?php

namespace TypePhp\Build;

use RuntimeException;

final class WasiToolchain
{
    public const MIN_LLVM_MAJOR = 22;
    public const MIN_WASMTIME_MAJOR = 47;

    /** @return array<string, string> */
    public function detect(): array
    {
        $tools = [];
        foreach (['clang', 'clang++', 'llvm-ar', 'llvm-ranlib', 'llvm-nm', 'wasm-ld', 'wasmtime'] as $name) {
            $tools[$name] = $this->findExecutable($name);
        }

        $versions = [];
        foreach (['clang', 'clang++', 'llvm-ar', 'llvm-ranlib', 'llvm-nm', 'wasm-ld'] as $name) {
            $versions[$name] = $this->requireVersion($name, $tools[$name], self::MIN_LLVM_MAJOR);
        }
        $versions['wasmtime'] = $this->requireVersion('wasmtime', $tools['wasmtime'], self::MIN_WASMTIME_MAJOR);

        [$exitCode, $target, $error] = $this->run([$tools['clang++'], '--print-target-triple']);
        $target = trim($target);
        if ($exitCode !== 0 || preg_match('/^wasm32-(?:unknown-)?wasi(?:p1)?$/', $target) !== 1) {
            $detail = trim($error) !== '' ? ': ' . trim($error) : '';
            throw new RuntimeException(
                "clang++ from PATH is not configured for wasm32-wasi (reported target: "
                . ($target !== '' ? $target : 'unknown') . "){$detail}",
            );
        }

        $tools['target'] = $target;
        $tools['clang-version'] = $versions['clang++'];
        $tools['wasmtime-version'] = $versions['wasmtime'];
        return $tools;
    }

    private function findExecutable(string $name): string
    {
        $path = getenv('PATH');
        foreach (explode(PATH_SEPARATOR, is_string($path) ? $path : '') as $directory) {
            if ($directory === '') {
                continue;
            }
            $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
            if (is_file($candidate) && is_executable($candidate)) {
                // Preserve the PATH entry instead of resolving symlinks. LLVM
                // multicall binaries select their driver mode and adjacent
                // .cfg file from argv[0] (notably clang++ and wasm-ld).
                return $candidate;
            }
        }

        throw new RuntimeException("Required WASI tool `{$name}` was not found in PATH");
    }

    private function requireVersion(string $name, string $executable, int $minimumMajor): string
    {
        [$exitCode, $output, $error] = $this->run([$executable, '--version']);
        $versionText = trim($output . "\n" . $error);
        if ($exitCode !== 0 || preg_match('/(?:version|wasmtime|LLD)\s+((\d+)(?:\.\d+)+)/i', $versionText, $match) !== 1) {
            throw new RuntimeException("Unable to determine the version of WASI tool `{$name}` from PATH");
        }
        $major = (int) $match[2];
        if ($major < $minimumMajor) {
            throw new RuntimeException(
                "WASI tool `{$name}` {$major} is too old; version {$minimumMajor} or newer is required",
            );
        }
        return $match[1];
    }

    /** @return array{int, string, string} */
    private function run(array $command): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return [127, '', 'failed to start process'];
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}
