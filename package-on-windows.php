#!/usr/bin/env php
<?php
/**
 * Windows 打包脚本
 * 将构建好的 swoole_compiler.exe 及相关文件打包为 zip
 * 参考 package.sh，但针对 Windows 特性进行了调整
 */

// 检查是否在 Windows 环境下运行
if (PHP_OS_FAMILY !== 'Windows') {
    echo "警告: 此脚本专为 Windows 设计，当前系统: " . PHP_OS . "\n";
    echo "继续使用可能出现问题...\n\n";
}

echo "========================================\n";
echo "Swoole Compiler Windows 打包脚本\n";
echo "========================================\n\n";

// ==================== 配置参数 ====================
// 从环境变量读取 PHP 和 phpx 目录
$phpDir = getenv('PHP_HOME');
$phpxDir = getenv('PHPX_HOME');

// 如果环境变量未设置，使用默认值
if (empty($phpDir)) {
    echo "警告: PHP_HOME 环境变量未设置\n";
    echo "请设置 PHP_HOME 指向 PHP 安装目录\n";
    echo "例如: set PHP_HOME=C:\\php\\php-8.4.20\n\n";
    exit(1);
}

if (empty($phpxDir)) {
    echo "警告: PHPX_HOME 环境变量未设置\n";
    echo "请设置 PHPX_HOME 指向 phpx 项目目录\n";
    echo "例如: set PHPX_HOME=D:\\workspace\\phpx\n\n";
    exit(1);
}

echo "PHP 目录: {$phpDir}\n";
echo "phpx 目录: {$phpxDir}\n\n";

$compilerExe = 'swoole_compiler.exe';
$versionFile = 'version.txt';

// ==================== 1. 版本号管理 ====================
echo "[1/7] 处理版本号...\n";

if (file_exists($versionFile)) {
    $versionId = (int)trim(file_get_contents($versionFile));
} else {
    $versionId = 1000;
}

$versionId++;
file_put_contents($versionFile, (string)$versionId);

echo "当前版本: {$versionId}\n\n";

// ==================== 2. 检测系统架构 ====================
echo "[2/7] 检测系统架构...\n";

$arch = match (getenv('PROCESSOR_ARCHITECTURE')) {
    'AMD64' => 'x86_64',
    'ARM64' => 'arm64',
    'x86' => 'i386',
    default => getenv('PROCESSOR_ARCHITECTURE') ?: 'unknown',
};

$osType = 'windows';
$outputFile = "swoole_compiler_v{$versionId}_{$osType}_{$arch}.zip";

echo "操作系统: {$osType}\n";
echo "硬件架构: {$arch}\n";
echo "输出文件: {$outputFile}\n\n";

// ==================== 3. 检查必要文件 ====================
echo "[3/7] 检查必要文件...\n";

$requiredFiles = [
    $compilerExe,
    'README.md',
    'LICENSE.md',
    'composer.json',
    'examples/hello.php',
];

foreach ($requiredFiles as $file) {
    if (!file_exists($file)) {
        echo "错误: 文件不存在 - {$file}\n";
        exit(1);
    }
}

echo "所有文件检查通过\n\n";

// ==================== 4. UPX 压缩（可选） ====================
echo "[4/7] UPX 压缩优化...\n";

$useUpx = false;
$backupExe = "{$compilerExe}.backup";

// 检查 UPX 是否可用
exec('where upx 2>nul', $upxOutput, $upxReturn);
if ($upxReturn === 0) {
    // 获取 UPX 版本
    exec('upx --version 2>&1', $versionOutput);
    $upxVersion = $versionOutput[0] ?? 'unknown';
    echo "检测到 UPX: {$upxVersion}\n";
    
    // 备份原始文件
    copy($compilerExe, $backupExe);
    echo "备份原始文件: {$backupExe}\n";
    
    // 使用 UPX 压缩
    echo "使用 UPX 压缩 {$compilerExe} ...\n";
    exec("upx --best \"{$compilerExe}\" 2>&1", $upxOutput, $upxResult);
    
    if ($upxResult === 0) {
        echo "✓ UPX 压缩完成\n";
        
        // 显示压缩效果
        $originalSize = filesize($backupExe);
        $compressedSize = filesize($compilerExe);
        $originalSizeMB = round($originalSize / 1024 / 1024, 2);
        $compressedSizeMB = round($compressedSize / 1024 / 1024, 2);
        $compressionRatio = round((1 - $compressedSize / $originalSize) * 100, 2);
        
        echo "  原始大小: {$originalSizeMB} MB\n";
        echo "  压缩后: {$compressedSizeMB} MB\n";
        echo "  压缩率: {$compressionRatio}%\n";
        
        $useUpx = true;
    } else {
        echo "✗ UPX 压缩失败，恢复原始文件\n";
        rename($backupExe, $compilerExe);
        $useUpx = false;
    }
} else {
    echo "未找到 UPX，跳过压缩步骤\n";
    echo "提示: 安装 UPX 可以显著减小编译器体积\n";
}

echo "\n";

// ==================== 5. 准备 PHP 目录结构 ====================
echo "[5/7] 准备 PHP 目录结构...\n";

// 创建顶层目录（压缩包根目录）
$topLevelDir = "swoole_compiler_v{$versionId}_{$osType}_{$arch}";

// 清理旧的临时目录
if (is_dir($topLevelDir)) {
    echo "清理旧的临时目录...\n";
    removeDirectory($topLevelDir);
}

// 创建顶层目录
mkdir($topLevelDir, 0755, true);
echo "创建顶层目录: {$topLevelDir}\n";

// 复制编译器可执行文件到顶层目录
copy($compilerExe, "{$topLevelDir}/{$compilerExe}");
echo "复制 {$compilerExe} -> {$topLevelDir}/\n";

// 递归复制 PHP_HOME 下的所有文件到顶层目录
if (!is_dir($phpDir)) {
    echo "警告: PHP 目录不存在 - {$phpDir}\n";
    echo "跳过 PHP 文件复制\n\n";
} else {
    echo "复制 PHP 运行时文件...\n";
    // 排除 dev/php8ts.lib（SDK 目录下已有）和根目录的 php8embed.lib（SDK/lib/ 目录下已有）
    copyDirectory($phpDir, $topLevelDir, ['dev/php8ts.lib', 'php8embed.lib']);
    
    // 统计复制的文件数量
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($topLevelDir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    $fileCount = iterator_count($iterator);
    echo "已复制 {$fileCount} 个文件\n";
}

echo "\n";

// ==================== 6. 复制项目文件到 PHP 目录 ====================
echo "[6/7] 复制项目文件到 PHP 目录...\n";

// 在顶层目录下创建子目录
mkdir("{$topLevelDir}/examples", 0755, true);

// 复制项目文件
$projectFiles = [
    'README.md' => '.',
    'LICENSE.md' => '.',
    'composer.json' => '.',
    'examples/hello.php' => 'examples',
];

foreach ($projectFiles as $src => $destDir) {
    $destPath = "{$topLevelDir}/{$destDir}";
    if (!is_dir($destPath)) {
        mkdir($destPath, 0755, true);
    }
    copy($src, "{$destPath}/" . basename($src));
    echo "  复制: {$src} -> {$destPath}/\n";
}

// 复制 win32-hello 示例目录（Windows 编程实例）
$win32HelloDir = 'examples/win32-hello';
if (is_dir($win32HelloDir)) {
    echo "  复制: {$win32HelloDir} -> examples/win32-hello/\n";
    // 排除 .obj 文件（MSVC 目标文件）
    copyDirectory($win32HelloDir, "{$topLevelDir}/examples/win32-hello", [], null, ['obj']);
} else {
    echo "  警告: {$win32HelloDir} 目录不存在\n";
}

// 复制 tetris-win32 示例目录（俄罗斯方块游戏）
$tetrisWin32Dir = 'examples/tetris-win32';
if (is_dir($tetrisWin32Dir)) {
    echo "  复制: {$tetrisWin32Dir} -> examples/tetris-win32/\n";
    // 排除 .obj 文件（MSVC 目标文件）
    copyDirectory($tetrisWin32Dir, "{$topLevelDir}/examples/tetris-win32", [], null, ['obj']);
} else {
    echo "  警告: {$tetrisWin32Dir} 目录不存在\n";
}

echo "项目文件复制完成\n\n";

// ==================== 6.3. 复制 vendor 目录 ====================
echo "[6.3/7] 复制 vendor 目录...\n";

$vendorDir = 'vendor';
if (!is_dir($vendorDir)) {
    echo "警告: vendor 目录不存在\n";
    echo "请先运行 composer install\n\n";
} else {
    echo "复制 vendor 目录...\n";
    // 排除 vendor/swoole/phpx 目录（Windows 下不需要 composer 安装的 phpx）
    copyDirectory($vendorDir, "{$topLevelDir}/vendor", ['swoole/phpx']);
    
    // 统计 vendor 目录的文件数量
    $vendorIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator("{$topLevelDir}/vendor"),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    $vendorFileCount = iterator_count($vendorIterator);
    echo "已复制 {$vendorFileCount} 个文件\n";
}

echo "\n";

// ==================== 6.5. 复制 phpx 文件 ====================
echo "[6.5/7] 复制 phpx 相关文件...\n";

// 检查 phpx 目录是否存在
if (!is_dir($phpxDir)) {
    echo "警告: phpx 目录不存在 - {$phpxDir}\n";
    echo "跳过 phpx 文件复制\n\n";
} else {
    // 创建 phpx 子目录
    mkdir("{$topLevelDir}/phpx/include", 0755, true);
    mkdir("{$topLevelDir}/phpx/lib", 0755, true);
    
    // 复制 phpx include 头文件
    $phpxIncludeDir = "{$phpxDir}/include";
    if (is_dir($phpxIncludeDir)) {
        copyDirectory($phpxIncludeDir, "{$topLevelDir}/phpx/include");
        echo "  复制: phpx/include -> phpx/include/\n";
    } else {
        echo "  警告: phpx/include 目录不存在\n";
    }
    
    // 复制 phpx lib 库文件（只保留 phpx.lib）
    $phpxLibDir = "{$phpxDir}/lib";
    $phpxLibFile = "{$phpxLibDir}/phpx.lib";
    if (file_exists($phpxLibFile)) {
        copy($phpxLibFile, "{$topLevelDir}/phpx/lib/phpx.lib");
        echo "  复制: phpx.lib -> phpx/lib/\n";
    } else {
        echo "  警告: phpx.lib 文件不存在\n";
    }
    
    // 复制 phpx/src/misc 目录（辅助工具和头文件）
    $phpxMiscDir = "{$phpxDir}/src/misc";
    if (is_dir($phpxMiscDir)) {
        mkdir("{$topLevelDir}/phpx/src/misc", 0755, true);
        // 排除 .obj 文件（MSVC 目标文件）和 .d 文件（依赖文件）
        copyDirectory($phpxMiscDir, "{$topLevelDir}/phpx/src/misc", [], null, ['obj', 'd']);
        echo "  复制: phpx/src/misc -> phpx/src/misc/\n";
    } else {
        echo "  警告: phpx/src/misc 目录不存在\n";
    }
    
    // 复制 phpx.dll 到顶层目录
    $phpxDllCandidates = [
        "{$phpxDir}/build/phpx.dll",
        "{$phpxDir}/bin/phpx.dll",
        "{$phpxDir}/phpx.dll",
    ];
    
    $dllCopied = false;
    foreach ($phpxDllCandidates as $dllPath) {
        if (file_exists($dllPath)) {
            copy($dllPath, "{$topLevelDir}/phpx.dll");
            echo "  复制: phpx.dll -> {$topLevelDir}/\n";
            $dllCopied = true;
            break;
        }
    }
    
    if (!$dllCopied) {
        echo "  警告: 未找到 phpx.dll 文件\n";
    }
    
    echo "phpx 文件复制完成\n\n";
}

// ==================== 7. 创建压缩包 ====================
echo "[7/7] 创建压缩包...\n";

// 删除旧的压缩包
if (file_exists($outputFile)) {
    unlink($outputFile);
    echo "已删除旧的压缩包\n";
}

// 使用 ZipArchive 创建 zip
if (!class_exists('ZipArchive')) {
    echo "错误: ZipArchive 扩展未启用\n";
    echo "请在 php.ini 中启用 extension=zip\n";
    
    // 恢复原始文件
    if ($useUpx && file_exists($backupExe)) {
        rename($backupExe, $compilerExe);
    }
    
    // 清理临时目录
    if (is_dir($tempPhpDir)) {
        removeDirectory($tempPhpDir);
    }
    
    exit(1);
}

$zip = new ZipArchive();
if ($zip->open($outputFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    echo "错误: 无法创建压缩包\n";
    
    // 恢复原始文件
    if ($useUpx && file_exists($backupExe)) {
        rename($backupExe, $compilerExe);
    }
    
    // 清理临时目录
    if (is_dir($tempPhpDir)) {
        removeDirectory($tempPhpDir);
    }
    
    exit(1);
}

// 添加文件到压缩包
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($topLevelDir),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($files as $file) {
    if (!$file->isDir()) {
        $filePath = $file->getRealPath();
        // 统一使用正斜杠
        $topLevelDirNormalized = str_replace('\\', '/', realpath($topLevelDir));
        $filePathNormalized = str_replace('\\', '/', $filePath);
        $relativePath = substr($filePathNormalized, strlen($topLevelDirNormalized) + 1);
        // 在相对路径前加上顶层目录名
        $zipPath = "{$topLevelDir}/{$relativePath}";
        $zip->addFile($filePath, $zipPath);
    }
}

$zip->close();
echo "✓ 压缩包创建成功\n\n";

// ==================== 8. 清理和恢复 ====================
echo "[8/8] 清理临时文件...\n";

// 获取文件大小
$packageSize = filesize($outputFile);
$packageSizeMB = round($packageSize / 1024 / 1024, 2);

echo "临时目录: {$topLevelDir}\n";
removeDirectory($topLevelDir);
echo "临时目录已清理\n";

// 如果使用了 UPX，恢复原始文件
if ($useUpx && file_exists($backupExe)) {
    echo "恢复原始二进制文件...\n";
    rename($backupExe, $compilerExe);
    @unlink($backupExe);
    echo "原始文件已恢复\n";
}

echo "\n";

// ==================== 最终报告 ====================
echo "========================================\n";
echo "打包完成！\n";
echo "========================================\n\n";
echo "版本号: {$versionId}\n";
echo "输出文件: {$outputFile}\n";
echo "文件大小: {$packageSizeMB} MB\n\n";
echo "包含内容:\n";
echo "  - {$compilerExe} (编译器可执行文件)\n";
echo "  - phpx.dll (PHPX 运行时库)\n";
echo "  - phpx/include/ (PHPX 头文件)\n";
echo "  - phpx/lib/ (PHPX 库文件)\n";
echo "  - phpx/src/misc/ (PHPX 辅助工具和头文件)\n";
echo "  - PHP 运行时环境 (完整目录结构)\n";
echo "  - vendor/ (Composer 依赖包，无需再次安装)\n";
echo "  - composer.json (Composer 配置文件)\n";
echo "  - README.md/LICENSE.md (文档)\n";
echo "  - examples/hello.php (PHP 示例代码)\n";
echo "  - examples/win32-hello/ (Windows GUI 编程实例)\n";
echo "  - examples/tetris-win32/ (俄罗斯方块游戏实例)\n\n";
echo "使用说明:\n";
echo "  1. 解压到任意目录\n";
echo "  2. 确保 PHP 8.4+ 已安装并添加到 PATH\n";
echo "  3. 运行: php swoole_compiler.php <your_script.php>\n\n";

/**
 * 递归复制目录
 * @param string $src 源目录
 * @param string $dest 目标目录
 * @param array $excludeDirs 要排除的目录列表（相对于 src 的路径）
 * @param string $baseSrc 原始源目录（用于计算相对路径）
 * @param array $excludeExtensions 要排除的文件扩展名列表（不含点）
 */
function copyDirectory(string $src, string $dest, array $excludeDirs = [], ?string $baseSrc = null, array $excludeExtensions = []): void
{
    if (!is_dir($src)) {
        return;
    }
    
    if ($baseSrc === null) {
        $baseSrc = $src;
    }
    
    if (!is_dir($dest)) {
        mkdir($dest, 0755, true);
    }
    
    $files = array_diff(scandir($src), ['.', '..']);
    foreach ($files as $file) {
        $srcPath = "{$src}/{$file}";
        $destPath = "{$dest}/{$file}";
        
        // 如果是文件，检查扩展名是否在排除列表中
        if (is_file($srcPath) && !empty($excludeExtensions)) {
            $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($ext, $excludeExtensions)) {
                continue;
            }
        }
        
        // 计算相对于 baseSrc 的路径
        $relativePath = str_replace('\\', '/', substr($srcPath, strlen($baseSrc) + 1));
        
        // 检查当前路径是否在排除列表中
        $shouldExclude = false;
        foreach ($excludeDirs as $excludeDir) {
            if (strpos($relativePath, $excludeDir) === 0 || $relativePath === $excludeDir) {
                $shouldExclude = true;
                break;
            }
        }
        
        if ($shouldExclude) {
            continue;
        }
        
        if (is_dir($srcPath)) {
            copyDirectory($srcPath, $destPath, $excludeDirs, $baseSrc, $excludeExtensions);
        } else {
            copy($srcPath, $destPath);
        }
    }
}

/**
 * 递归删除目录
 */
function removeDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    
    // 使用 RecursiveIteratorIterator 更高效地删除深层目录
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            // 移除只读属性（Windows）
            if (PHP_OS_FAMILY === 'Windows' && !is_writable($file->getRealPath())) {
                @chmod($file->getRealPath(), 0666);
            }
            @unlink($file->getRealPath());
        }
    }
    
    // 最后删除根目录
    @rmdir($dir);
}
