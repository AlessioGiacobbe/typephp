# CompilerBase 和 Translator 迁移指南

## 概述

本文档说明如何将 `CompilerBase.php` 和 `Translator.php` 逐步迁移到新的 Platform 和 Backend 抽象层。

## 当前状态

✅ **已完成：**
- 在 `CompilerBase` 中添加了新抽象层的属性
- 添加了自动初始化逻辑（`initializeNewArchitecture()`）
- 保持了完全向后兼容

⏳ **进行中：**
- 逐步替换旧的编译逻辑
- 使用新的 Backend 类生成命令

## 渐进式迁移策略

### 阶段 1：双轨运行（当前）

新旧代码并存，优先使用新架构，失败时回退到旧逻辑：

```php
// CompilerBase.php 中的初始化
protected function initializeNewArchitecture(): void
{
    try {
        // 尝试使用新架构
        $result = \PhpAot\Php\Backend\CompilerFactory::autoDetect($this->cppCompiler);
        $this->platform = $result['platform'];
        $this->compilerBackend = $result['compiler'];
        
        $this->climate->info(
            "Initialized new architecture: {$this->platform->getName()} + {$this->compilerBackend->getName()}"
        );
    } catch (\Exception $e) {
        // 失败时回退到旧逻辑
        $this->climate->warning(
            "Failed to initialize new architecture: {$e->getMessage()}. Using legacy mode."
        );
        $this->platform = null;
        $this->compilerBackend = null;
    }
}
```

### 阶段 2：选择性使用新 API

在特定方法中使用新架构，例如：

```php
protected function parseIncludes(): string
{
    // 如果新架构可用，使用它
    if ($this->platform !== null) {
        $includePaths = $this->getIncludePaths();
        return $this->platform->getIncludeFlags($includePaths);
    }
    
    // 否则使用旧逻辑
    return $this->parseIncludesLegacy();
}
```

### 阶段 3：全面迁移

当新架构稳定后，逐步替换所有相关方法。

## 需要改造的方法清单

### CompilerBase.php

#### 高优先级（核心编译逻辑）
- [ ] `parseIncludes()` - 使用 `$platform->getIncludeFlags()`
- [ ] `parseLdflags()` - 使用 `$platform->getLibraryPathFlags()`
- [ ] `parseLibs()` - 使用 `$platform->getLibraryFlags()`
- [ ] `addCompilationOption()` - 使用 `$compilerBackend->buildCompileCommand()`
- [ ] `compileFile()` - 使用 `$compilerBackend->compileFile()`
- [ ] `linkObjects()` - 使用 `$compilerBackend->linkObjects()`

#### 中优先级（平台特定逻辑）
- [ ] `parseWindowsIncludes()` - 整合到 Platform 层
- [ ] `parseWindowsLdflags()` - 整合到 Platform 层
- [ ] `parseWindowsLibs()` - 整合到 Platform 层
- [ ] `detectWindowsPhpLibs()` - 整合到 Windows Platform
- [ ] `addWindowsCompilationOption()` - 使用 MSVC Backend
- [ ] `addWindowsClangCompilationOption()` - 使用 Clang Backend
- [ ] `addUnixCompilationOption()` - 使用 GCC Backend

#### 低优先级（辅助方法）
- [ ] `isWindows()` - 使用 `$platform instanceof Windows`
- [ ] `isMacos()` - 使用 `$platform instanceof Macos`
- [ ] 路径处理相关方法 - 使用 Platform 的路径方法

### Translator.php

#### 需要检查的地方
- [ ] 直接使用编译器命令的地方
- [ ] 平台特定的代码生成
- [ ] 路径拼接和处理

## 使用示例

### 示例 1：使用新架构生成编译命令

```php
// 在 CompilerBase 中
protected function generateCompileCommand(string $sourceFile, string $outputFile): string
{
    // 如果新架构可用
    if ($this->compilerBackend !== null) {
        return $this->compilerBackend->buildCompileCommand(
            $sourceFile,
            $outputFile,
            [
                'optimize' => $this->optimizeLevel,
                'debug' => $this->debugInfo,
                'cpp_std' => $this->cxxStd,
                'pic' => ($this->buildMode === 'ext'),
            ]
        );
    }
    
    // 否则使用旧逻辑
    return $this->generateCompileCommandLegacy($sourceFile, $outputFile);
}
```

### 示例 2：使用新架构生成链接命令

```php
protected function generateLinkCommand(array $objectFiles, string $outputFile): string
{
    if ($this->compilerBackend !== null) {
        $options = [
            'debug' => $this->debugInfo,
            'shared' => ($this->buildMode === 'ext'),
        ];
        
        // Windows 特定选项
        if ($this->platform instanceof \PhpAot\Php\Platform\Windows) {
            $options['no_console'] = $this->noConsole;
        }
        
        // Unix/macOS 特定选项
        if ($this->platform instanceof \PhpAot\Php\Platform\Linux ||
            $this->platform instanceof \PhpAot\Php\Platform\Macos) {
            $options['rpath'] = [
                $this->getPhpDir() . '/lib',
                $this->getPhpxDir() . '/lib',
            ];
        }
        
        return $this->compilerBackend->buildLinkCommand(
            $objectFiles,
            $outputFile,
            $options
        );
    }
    
    // 否则使用旧逻辑
    return $this->generateLinkCommandLegacy($objectFiles, $outputFile);
}
```

### 示例 3：使用 Platform 处理路径

```php
protected function buildObjectFilePath(string $sourceFile): string
{
    if ($this->platform !== null) {
        $baseName = basename($sourceFile, '.cpp');
        return $this->platform->joinPath(
            $this->buildDir,
            $baseName . $this->platform->getObjectExtension()
        );
    }
    
    // 旧逻辑
    $ext = $this->isWindows() ? '.obj' : '.o';
    return $this->buildDir . '/' . basename($sourceFile, '.cpp') . $ext;
}
```

## 测试策略

### 1. 单元测试
为每个新方法编写单元测试：
```php
class PlatformTest extends TestCase
{
    public function testWindowsIncludeFlags()
    {
        $platform = new Windows();
        $flags = $platform->getIncludeFlags(['C:\\PHP\\include']);
        $this->assertStringContainsString('/I "C:\\PHP\\include"', $flags);
    }
}
```

### 2. 集成测试
测试完整的编译流程：
```php
public function testFullCompilationWithNewArchitecture()
{
    $compiler = new CompilerBase('/path/to/project');
    
    // 验证新架构已初始化
    $this->assertNotNull($compiler->platform);
    $this->assertNotNull($compiler->compilerBackend);
    
    // 执行编译
    $result = $compiler->compile('test.php');
    $this->assertTrue($result);
}
```

### 3. 回归测试
确保旧功能仍然正常工作：
```php
public function testLegacyModeStillWorks()
{
    // 强制使用旧模式
    $compiler = new CompilerBase('/path/to/project');
    $compiler->platform = null;
    $compiler->compilerBackend = null;
    
    // 应该回退到旧逻辑并正常工作
    $result = $compiler->compile('test.php');
    $this->assertTrue($result);
}
```

## 迁移检查清单

### CompilerBase.php
- [ ] 添加新属性（已完成）
- [ ] 添加初始化逻辑（已完成）
- [ ] 替换 `parseIncludes()`
- [ ] 替换 `parseLdflags()`
- [ ] 替换 `parseLibs()`
- [ ] 替换 `addCompilationOption()`
- [ ] 替换编译文件逻辑
- [ ] 替换链接逻辑
- [ ] 移除旧的 Windows 特定方法（最后）
- [ ] 移除旧的 Unix 特定方法（最后）
- [ ] 更新文档

### Translator.php
- [ ] 检查所有编译器调用
- [ ] 替换平台特定代码
- [ ] 使用 Platform 的路径方法
- [ ] 测试所有翻译场景

## 注意事项

### 1. 保持向后兼容
- 始终提供回退机制
- 不要立即删除旧代码
- 先标记为 deprecated，再逐步移除

### 2. 错误处理
- 新架构失败时要有清晰的错误信息
- 记录详细的日志以便调试
- 提供切换到旧模式的选项

### 3. 性能考虑
- 新架构不应该比旧代码慢
- 避免不必要的对象创建
- 缓存常用结果

### 4. 文档更新
- 更新 PHPDoc 注释
- 添加使用示例
- 记录 breaking changes

## 下一步行动

1. **立即可以做：**
   - 测试当前的初始化逻辑
   - 验证新架构可以正确检测平台和编译器
   - 编写基础单元测试

2. **短期目标（1-2周）：**
   - 替换 `parseIncludes()`、`parseLdflags()`、`parseLibs()`
   - 添加集成测试
   - 收集用户反馈

3. **中期目标（1个月）：**
   - 替换核心编译和链接逻辑
   - 完善错误处理
   - 性能优化

4. **长期目标（2-3个月）：**
   - 完全迁移到新架构
   - 移除旧代码
   - 发布新版本

## 总结

这是一个**渐进式迁移**，目标是：
- ✅ 保持向后兼容
- ✅ 降低风险
- ✅ 逐步改进
- ✅ 易于回退

不要一次性重写所有代码，而是逐步替换，每一步都经过充分测试！
