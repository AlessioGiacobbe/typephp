<?php

/**
 * 新架构使用示例
 * 
 * 展示如何使用 Platform 和 Backend 抽象层
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use PhpAot\Php\Platform\PlatformFactory;
use PhpAot\Php\Backend\CompilerFactory;

echo "=== 平台和编译器抽象层示例 ===\n\n";

// 示例 1：自动检测平台和编译器
echo "1. 自动检测平台和编译器:\n";
$result = CompilerFactory::autoDetect();
$platform = $result['platform'];
$compiler = $result['compiler'];

echo "   平台: {$platform->getName()}\n";
echo "   编译器: {$compiler->getName()}\n";
echo "   对象文件扩展名: {$platform->getObjectExtension()}\n";
echo "   可执行文件扩展名: {$platform->getExecutableExtension()}\n";
echo "\n";

// 示例 2：构建编译命令
echo "2. 构建编译命令:\n";
$compileCmd = $compiler->buildCompileCommand(
    'test.cpp',
    'test' . $platform->getObjectExtension(),
    [
        'optimize' => 2,
        'debug' => false,
        'cpp_std' => 'c++17',
    ]
);
echo "   {$compileCmd}\n";
echo "\n";

// 示例 3：构建链接命令
echo "3. 构建链接命令:\n";
$linkCmd = $compiler->buildLinkCommand(
    ['test' . $platform->getObjectExtension()],
    'output' . $platform->getExecutableExtension(),
    [
        'debug' => true,
        'no_console' => false,
    ]
);
echo "   {$linkCmd}\n";
echo "\n";

// 示例 4：手动指定平台和编译器
echo "4. 手动指定平台和编译器:\n";
use PhpAot\Php\Platform\Windows;
use PhpAot\Php\Backend\Msvc;

$windowsPlatform = new Windows(
    phpLibs: ['php8embed.lib', 'php8ts.lib'],
    isZts: true
);
$msvcCompiler = new Msvc($windowsPlatform);

echo "   平台: {$windowsPlatform->getName()}\n";
echo "   编译器: {$msvcCompiler->getName()}\n";
echo "   ZTS: " . ($windowsPlatform->isZts() ? 'Yes' : 'No') . "\n";
echo "\n";

// 示例 5：跨平台路径处理
echo "5. 跨平台路径处理:\n";
$path = $platform->joinPath('src', 'Php', 'Backend');
echo "   组合路径: {$path}\n";
echo "   路径分隔符: '{$platform->getPathSeparator()}'\n";
echo "\n";

echo "=== 示例完成 ===\n";
