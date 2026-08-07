<?php
use TypePhp\Translator;
use TypePhp\Build\WasiToolchain;

function main(int $argc, array $argv): void
{
    // Compiling a complete project keeps the parsed AST and generated sources in
    // memory. The default CLI limit (commonly 128M) is too small for larger builds.
    ini_set('memory_limit', '-1');

    if (!defined('ROOT_PATH')) {
        define("ROOT_PATH", getcwd());
    }

    if (in_array('--wasm', $argv, true)) {
        compileWasmProgram($argv);
        return;
    }

    // .prof 文件分析模式：./tpc app.prof
    if ($argc >= 2 && str_ends_with($argv[1], '.prof')) {
        profileAnalyze($argc, $argv);
        return;
    }

    require_once ROOT_PATH . '/vendor/autoload.php';
    global $translator;

    $translator = new Translator(ROOT_PATH);
    $translator->setIndent('    ');
    // 扫描所有 PHP 文件，预处理
    $files = $translator->prepare($translator->parseArgv($argv));
    // 生成 C++ 文件
    $sourceFiles = $translator->convert($files);

    // --dry 模式：仅生成 C++ 代码，不执行编译
    if ($translator->isDryRun()) {
        $buildDir = $translator->getBuildDir();
        $count = count($sourceFiles);
        $sourceListFile = getenv('TYPEPHP_GENERATED_SOURCE_LIST');
        if (is_string($sourceListFile) && $sourceListFile !== '') {
            $sourceListDir = dirname($sourceListFile);
            if (!is_dir($sourceListDir) && !mkdir($sourceListDir, 0777, true) && !is_dir($sourceListDir)) {
                throw new RuntimeException("Unable to create generated source manifest directory: {$sourceListDir}");
            }
            if (file_put_contents($sourceListFile, implode(PHP_EOL, $sourceFiles) . PHP_EOL) === false) {
                throw new RuntimeException("Unable to write generated source manifest: {$sourceListFile}");
            }
        }
        $translator->output("Dry run completed: {$count} C++ source file(s) generated in {$buildDir}", 'lightBlue');
        return;
    }

    // 编译所有 C++ 文件
    $objectFiles = $translator->compile($sourceFiles);
    // 连接所有目标文件，生成可执行文件
    $binaryFile = $translator->build($objectFiles);
    // 如果指定了 --run / -r，编译完成后立即执行
    if ($translator->isRunRequested()) {
        $translator->run($binaryFile); // never returns
    }
}

/**
 * Build a self-contained WASI command module through the compiler's public CLI.
 * The lower-level build scripts are implementation details and are not part of
 * the user-facing workflow.
 */
function compileWasmProgram(array $argv): void
{
    $input = null;
    $buildDir = null;
    $arguments = array_slice($argv, 1);
    for ($i = 0, $count = count($arguments); $i < $count; $i++) {
        $argument = $arguments[$i];
        if ($argument === '--wasm') {
            continue;
        }
        if ($argument === '--build-dir') {
            if (!isset($arguments[$i + 1]) || $arguments[$i + 1] === '') {
                fwrite(STDERR, "Option --build-dir requires a directory\n");
                exit(1);
            }
            $buildDir = $arguments[++$i];
            continue;
        }
        if (str_starts_with($argument, '--build-dir=')) {
            $buildDir = substr($argument, strlen('--build-dir='));
            if ($buildDir === '') {
                fwrite(STDERR, "Option --build-dir requires a directory\n");
                exit(1);
            }
            continue;
        }
        if (str_starts_with($argument, '-')) {
            fwrite(STDERR, "Unsupported option in --wasm mode: {$argument}\n");
            exit(1);
        }
        if ($input !== null) {
            fwrite(STDERR, "The --wasm mode accepts exactly one PHP input file\n");
            exit(1);
        }
        $input = $argument;
    }

    if ($input === null) {
        fwrite(STDERR, "Usage: php bin/tpc.php <program.php> --wasm [--build-dir <directory>]\n");
        exit(1);
    }

    $workingDirectory = getcwd();
    $buildDir ??= ROOT_PATH . DIRECTORY_SEPARATOR . 'build';
    if (!str_starts_with($buildDir, DIRECTORY_SEPARATOR)
        && preg_match('/^[A-Za-z]:[\\\\\/]/', $buildDir) !== 1) {
        $buildDir = $workingDirectory . DIRECTORY_SEPARATOR . $buildDir;
    }

    $builder = dirname(__DIR__) . '/projects/php-8.5.9/wasm/build-typephp-program.sh';
    if (!is_executable($builder)) {
        fwrite(STDERR, "TypePHP WASI builder is not executable: {$builder}\n");
        exit(1);
    }

    try {
        $tools = (new WasiToolchain())->detect();
    } catch (RuntimeException $exception) {
        fwrite(STDERR, "WASI toolchain check failed: {$exception->getMessage()}\n");
        fwrite(STDERR, "Add WASI SDK and Wasmtime bin directories to PATH, then try again.\n");
        exit(1);
    }

    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment['TYPEPHP_WASI_CC'] = $tools['clang'];
    $environment['TYPEPHP_WASI_CXX'] = $tools['clang++'];
    $environment['TYPEPHP_WASI_AR'] = $tools['llvm-ar'];
    $environment['TYPEPHP_WASI_RANLIB'] = $tools['llvm-ranlib'];
    $environment['TYPEPHP_WASI_NM'] = $tools['llvm-nm'];
    $environment['TYPEPHP_WASI_LD'] = $tools['wasm-ld'];
    $environment['TYPEPHP_WASMTIME'] = $tools['wasmtime'];
    $environment['TYPEPHP_WASI_TARGET'] = $tools['target'];
    $environment['TYPEPHP_WASI_CLANG_VERSION'] = $tools['clang-version'];
    $environment['TYPEPHP_WASMTIME_VERSION'] = $tools['wasmtime-version'];
    $environment['TYPEPHP_WASM_PROGRAM_BUILD_DIR'] = $buildDir;

    $process = proc_open(
        [$builder, $input],
        [STDIN, STDOUT, STDERR],
        $pipes,
        getcwd(),
        $environment,
    );
    if (!is_resource($process)) {
        fwrite(STDERR, "Failed to start the TypePHP WASI builder\n");
        exit(1);
    }

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        exit($exitCode);
    }
}

function profileAnalyze(int $argc, array $argv): void
{
    $profFile = $argv[1];

    if (!file_exists($profFile)) {
        fwrite(STDERR, "Profile file not found: {$profFile}\n");
        exit(1);
    }

    // 从 prof 文件名推导二进制文件名（app.prof → app）
    $binary = basename($profFile, '.prof');
    if (!file_exists($binary) && file_exists('./' . $binary)) {
        $binary = './' . $binary;
    }

    if (!file_exists($binary)) {
        fwrite(STDERR, "Binary not found: {$binary} (expected from prof file name)\n");
        fwrite(STDERR, "Usage: ./tpc <binary>.prof\n");
        exit(1);
    }

    $cmd = 'pprof --web ' . escapeshellarg($binary) . ' ' . escapeshellarg($profFile);
    fwrite(STDERR, "Running: {$cmd}\n");
    passthru($cmd, $exitCode);
    exit($exitCode);
}
