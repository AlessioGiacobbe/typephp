<?php
use PhpAot\Php\Translator;

function main(int $argc, array $argv): void
{
    if (!defined('ROOT_PATH')) {
        define("ROOT_PATH", getcwd());
    }

    // .prof 文件分析模式：php bin/compiler.php app.prof
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
        fwrite(STDERR, "Usage: php bin/compiler.php <binary>.prof\n");
        exit(1);
    }

    $cmd = 'pprof --web ' . escapeshellarg($binary) . ' ' . escapeshellarg($profFile);
    fwrite(STDERR, "Running: {$cmd}\n");
    passthru($cmd, $exitCode);
    exit($exitCode);
}
