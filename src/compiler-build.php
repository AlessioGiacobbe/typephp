<?php
use PhpAot\Php\Translator;

function main(int $argc, array $argv): void
{
    if (!defined('ROOT_PATH')) {
        define("ROOT_PATH", getcwd());
    }

    require_once ROOT_PATH . '/vendor/autoload.php';
    global $translator;

    $translator = new Translator(ROOT_PATH);
    $translator->setIndent('    ');
    // 扫描所有 PHP 文件，预处理
    $files = $translator->prepare($translator->parseArgv($argv));
    // 生成 C++ 文件
    $sourceFiles = $translator->convert($files);
    // 编译所有 C++ 文件
    $objectFiles = $translator->compile($sourceFiles);
    // 连接所有目标文件，生成可执行文件
    $binaryFile = $translator->build($objectFiles);
    // 如果指定了 --run / -r，编译完成后立即执行
    if ($translator->isRunRequested()) {
        $translator->run($binaryFile); // never returns
    }
}
