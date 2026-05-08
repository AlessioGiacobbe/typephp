# 快速开始 - 使用新架构

## 概述

本文档展示如何立即开始使用新的 Platform 和 Backend 抽象层，无需等待完整重构完成。

## 1. 基本用法

### 自动检测（推荐）

```php
use PhpAot\Php\Backend\CompilerFactory;

// 自动检测平台和编译器
$result = CompilerFactory::autoDetect();
$platform = $result['platform'];
$compiler = $result['compiler'];

echo "平台: {$platform->getName()}\n";
echo "编译器: {$compiler->getName()}\n";
```

### 手动指定

```php
use PhpAot\Php\Platform\Windows;
use PhpAot\Php\Backend\Msvc;

// 创建 Windows 平台
$platform = new Windows(
    phpLibs: ['php8embed.lib', 'php8ts.lib'],
    isZts: true
);

// 创建 MSVC 编译器
$compiler = new Msvc($platform);
```

## 2. 生成编译命令

### 简单模式

```php
$cmd = $compiler->buildCompileCommand(
    'test.cpp',
    'test.obj',
    [
        'optimize' => 2,
        'debug' => false,
        'cpp_std' => 'c++17',
    ]
);

// Windows MSVC 输出:
// cl /c "test.cpp" /Fo"test.obj" /DZEND_WIN32 /DPHP_WIN32 ... /O2 /W3 /std:c++17 /EHsc /MD /nologo
```

### 完整模式

```php
// 获取完整的编译选项
$options = $compiler->buildFullCompileOptions([
    'optimize' => 2,
    'debug_info' => false,
    'sanitize' => null,
    'cpp_std' => 'c++17',
    'suppressed_warnings' => [4996, 4267, 4244],
]);

// 手动构建命令
$cmd = $compiler->getCompilerCommand();
$cmd .= ' /c test.cpp';
$cmd .= ' /Fo test.obj';
$cmd .= ' ' . $platform->getIncludeFlags(['/path/to/include']);
$cmd .= $options;
```

## 3. 生成链接命令

```php
$cmd = $compiler->buildLinkCommand(
    ['test.obj'],
    'output.exe',
    [
        'debug' => true,
        'no_console' => false,
        'shared' => false,
    ]
);

// Windows MSVC 输出:
// link test.obj /OUT:"output.exe" /DEBUG /NODEFAULTLIB:LIBCMT /nologo
```

## 4. 平台特定功能

### Windows

```php
/** @var \PhpAot\Php\Platform\Windows $platform */

// 检测 PHP libs
$libInfo = $platform->detectPhpLibs('C:\\php');
echo "Embed lib: {$libInfo['embed']}\n";
echo "Core lib: {$libInfo['core']}\n";
echo "Is ZTS: " . ($libInfo['is_zts'] ? 'Yes' : 'No') . "\n";

// 构建 SDK 包含路径
$includePaths = $platform->buildPhpSdkIncludePaths('C:\\php');
// 返回: ['C:\php\SDK\include', 'C:\php\SDK\include\main', ...]

// 构建 SDK 库路径
$libPaths = $platform->buildPhpSdkLibPaths('C:\\php');
// 返回: ['C:\php\SDK\lib']
```

### Linux/macOS

```php
/** @var \PhpAot\Php\Platform\Linux $platform */

// 获取 RPATH 选项
$rpath = $platform->getRpathOptions(['/usr/lib', '/usr/local/lib']);
// 返回: '-Wl,-rpath,/usr/lib -Wl,-rpath,/usr/local/lib'

// 获取 PIC 标志
$pic = $platform->getPicFlag();
// 返回: '-fPIC'

// 获取共享库链接标志
$shared = $platform->getSharedLinkFlag();
// 返回: '-shared'
```

## 5. 在 CompilerBase 中使用

### 方法 1：直接使用新 API

```php
class MyCompiler extends CompilerBase
{
    protected function generateCompileCommand(string $source, string $output): string
    {
        // 如果新架构可用
        if ($this->compilerBackend !== null) {
            return $this->compilerBackend->buildCompileCommand(
                $source,
                $output,
                [
                    'optimize' => $this->optimizeLevel,
                    'debug' => $this->debugInfo,
                    'cpp_std' => $this->cxxStd,
                ]
            );
        }
        
        // 否则使用旧逻辑
        return $this->legacyGenerateCompileCommand($source, $output);
    }
}
```

### 方法 2：使用适配器

```php
class MyCompiler extends CompilerBase
{
    protected function parseIncludes(): string
    {
        // 优先使用新架构
        if ($this->platform !== null) {
            $paths = $this->getIncludePaths();
            return $this->platform->getIncludeFlags($paths);
        }
        
        // 回退到旧逻辑
        return parent::parseIncludes();
    }
}
```

## 6. 实用工具

### 检查当前环境

```php
use PhpAot\Php\Platform\PlatformFactory;

// 检查平台
if (PlatformFactory::isWindows()) {
    echo "Running on Windows\n";
} elseif (PlatformFactory::isLinux()) {
    echo "Running on Linux\n";
} elseif (PlatformFactory::isMacos()) {
    echo "Running on macOS\n";
}

// 获取平台名称
$name = PlatformFactory::getCurrentPlatformName();
echo "Current platform: {$name}\n";
```

### 路径处理

```php
// 组合路径（跨平台）
$path = $platform->joinPath('src', 'Php', 'Backend');
// Windows: src\Php\Backend
// Linux/macOS: src/Php/Backend

// 规范化路径
$normalized = $platform->normalizePath('src/Php/Backend');
// Windows: src\Php\Backend
// Linux/macOS: src/Php/Backend

// 获取文件扩展名
$objExt = $platform->getObjectExtension();
// Windows: .obj
// Linux/macOS: .o

$exeExt = $platform->getExecutableExtension();
// Windows: .exe
// Linux/macOS: (empty)
```

## 7. 完整示例

```php
<?php

require_once 'vendor/autoload.php';

use PhpAot\Php\Backend\CompilerFactory;
use PhpAot\Php\Platform\PlatformFactory;

// 1. 自动检测
$result = CompilerFactory::autoDetect();
$platform = $result['platform'];
$compiler = $result['compiler'];

echo "=== 编译配置 ===\n";
echo "平台: {$platform->getName()}\n";
echo "编译器: {$compiler->getName()}\n";
echo "对象扩展: {$platform->getObjectExtension()}\n";
echo "可执行扩展: {$platform->getExecutableExtension()}\n";
echo "\n";

// 2. 准备源文件
$sourceFile = 'test.cpp';
$objectFile = 'test' . $platform->getObjectExtension();
$outputFile = 'output' . $platform->getExecutableExtension();

// 3. 生成编译命令
echo "=== 编译命令 ===\n";
$compileCmd = $compiler->buildCompileCommand(
    $sourceFile,
    $objectFile,
    [
        'optimize' => 2,
        'debug' => false,
        'cpp_std' => 'c++17',
    ]
);
echo $compileCmd . "\n\n";

// 4. 生成链接命令
echo "=== 链接命令 ===\n";
$linkCmd = $compiler->buildLinkCommand(
    [$objectFile],
    $outputFile,
    [
        'debug' => true,
        'no_console' => false,
    ]
);
echo $linkCmd . "\n\n";

// 5. 执行编译（可选）
echo "=== 执行编译 ===\n";
echo "运行: {$compileCmd}\n";
// exec($compileCmd, $output, $returnCode);
// if ($returnCode === 0) {
//     echo "✓ 编译成功\n";
// } else {
//     echo "✗ 编译失败\n";
// }

echo "\n=== 完成 ===\n";
```

## 8. 最佳实践

### ✅ 推荐做法

1. **总是检查新架构是否可用**
   ```php
   if ($this->compilerBackend !== null) {
       // 使用新架构
   } else {
       // 回退到旧逻辑
   }
   ```

2. **使用工厂类创建实例**
   ```php
   $result = CompilerFactory::autoDetect();
   // 而不是手动 new
   ```

3. **利用 Platform 的路径方法**
   ```php
   $path = $platform->joinPath(...);
   // 而不是硬编码 '/' 或 '\\'
   ```

4. **传递选项数组而非多个参数**
   ```php
   $compiler->buildCompileCommand($src, $out, [
       'optimize' => 2,
       'debug' => false,
   ]);
   ```

### ❌ 避免的做法

1. **不要直接访问私有属性**
   ```php
   // 错误
   $cmd = $compiler->somePrivateMethod();
   
   // 正确
   $cmd = $compiler->buildCompileCommand(...);
   ```

2. **不要假设平台类型**
   ```php
   // 错误
   if ($platform instanceof Windows) {
       // Windows 特定代码
   }
   
   // 正确：让 Platform 自己处理
   $flags = $platform->getIncludeFlags($paths);
   ```

3. **不要忘记回退机制**
   ```php
   // 错误：没有回退
   return $this->compilerBackend->buildCompileCommand(...);
   
   // 正确：提供回退
   if ($this->compilerBackend !== null) {
       return $this->compilerBackend->buildCompileCommand(...);
   }
   return $this->legacyMethod(...);
   ```

## 9. 常见问题

### Q: 新架构初始化失败怎么办？

A: 系统会自动回退到旧逻辑，并显示警告信息。检查日志了解失败原因。

### Q: 如何强制使用旧逻辑？

A: 将 `$this->platform` 和 `$this->compilerBackend` 设置为 `null`。

### Q: 性能有影响吗？

A: 几乎没有。新架构只是封装了原有逻辑，额外开销可以忽略不计。

### Q: 可以混合使用新旧 API 吗？

A: 可以，但建议逐步迁移到新 API。

## 10. 下一步

1. 阅读 [REFACTORING_PLAN.md](REFACTORING_PLAN.md) 了解完整重构计划
2. 查看 [MIGRATION_GUIDE.md](MIGRATION_GUIDE.md) 了解迁移步骤
3. 运行 `test_integration.php` 验证集成
4. 开始在您的代码中使用新 API

## 总结

新架构已经可以使用！您可以：
- ✅ 立即开始使用新 API
- ✅ 保持向后兼容
- ✅ 渐进式迁移
- ✅ 随时回退

开始使用吧！🚀
