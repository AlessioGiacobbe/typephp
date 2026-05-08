# 完全解耦重构方案

## 目标

将 `CompilerBase.php` 和 `Translator.php` 中所有平台相关和编译器相关的代码完全迁移到 Platform 和 Backend 类中，实现真正的解耦。

## 重构原则

1. **单一职责**：每个类只负责一件事
2. **依赖倒置**：通过接口调用，不直接依赖具体实现
3. **开闭原则**：对扩展开放，对修改关闭
4. **渐进式迁移**：保持向后兼容，逐步替换

## 架构设计

```
┌─────────────────────────────────────┐
│      CompilerBase (协调者)          │
│  -  orchestrates compilation flow   │
│  -  delegates to Platform/Backend   │
└──────────┬──────────────┬───────────┘
           │              │
           ▼              ▼
┌─────────────────┐ ┌──────────────────┐
│   Platform      │ │   Backend        │
│  (平台抽象层)    │ │  (编译器抽象层)   │
│                 │ │                  │
│ - Windows       │ │ - Msvc           │
│ - Linux         │ │ - Gcc            │
│ - Macos         │ │ - Clang          │
└─────────────────┘ └──────────────────┘
```

## 迁移清单

### Phase 1: Platform 层增强（已完成 50%）

#### ✅ Windows Platform
- [x] `buildPhpSdkIncludePaths()` - 构建 PHP SDK 包含路径
- [x] `buildPhpSdkLibPaths()` - 构建 PHP SDK 库路径
- [x] `detectPhpLibs()` - 检测 PHP lib 文件
- [ ] `getCompilerFlags()` - 获取编译器标志
- [ ] `getLinkerFlags()` - 获取链接器标志

#### ⏳ Linux Platform  
- [ ] `getRpathOptions()` - RPATH 选项
- [ ] `getPicFlag()` - PIC 标志
- [ ] `getSharedLinkFlag()` - 共享库链接标志

#### ⏳ macOS Platform
- [ ] `getRpathOptions()` - RPATH 选项
- [ ] `getCurrentInstallNameOption()` - install_name 选项
- [ ] `getSharedLinkFlag()` - 动态库链接标志

### Phase 2: Backend 层增强（已完成 60%）

#### ✅ MSVC Backend
- [x] `buildFullCompileOptions()` - 完整编译选项
- [x] `buildFullLinkOptions()` - 完整链接选项
- [ ] `addSanitizerOptions()` - Sanitizer 选项
- [ ] `addWarningOptions()` - 警告选项

#### ⏳ GCC Backend
- [ ] `buildFullCompileOptions()`
- [ ] `buildFullLinkOptions()`

#### ⏳ Clang Backend
- [ ] `buildFullCompileOptions()`
- [ ] `buildFullLinkOptions()`

### Phase 3: CompilerBase 解耦（待开始）

需要迁移的方法：

#### 高优先级（核心逻辑）
1. `parseIncludes()` → `$platform->getIncludeFlags()` + `$backend->buildIncludeOptions()`
2. `parseLdflags()` → `$platform->getLibraryPathFlags()`
3. `parseLibs()` → `$platform->getLibraryFlags()`
4. `addCompilationOption()` → `$backend->buildFullCompileOptions()`
5. `compileFile()` → `$backend->compileFile()`
6. `linkObjects()` → `$backend->linkObjects()`

#### 中优先级（平台特定）
7. `parseWindowsIncludes()` → 删除，使用 Platform
8. `parseWindowsLdflags()` → 删除，使用 Platform
9. `parseWindowsLibs()` → 删除，使用 Platform
10. `detectWindowsPhpLibs()` → 删除，使用 `Windows::detectPhpLibs()`
11. `addWindowsCompilationOption()` → 删除，使用 MSVC Backend
12. `addWindowsClangCompilationOption()` → 删除，使用 Clang Backend
13. `addUnixCompilationOption()` → 删除，使用 GCC Backend

#### 低优先级（辅助方法）
14. `isWindows()` → `$platform instanceof Windows`
15. `isMacos()` → `$platform instanceof Macos`
16. 其他平台检测方法

### Phase 4: Translator 解耦（待开始）

检查 Translator.php 中直接使用编译器命令的地方，改为通过 Backend 调用。

## 实施步骤

### Step 1: 完善 Platform 类（1-2天）

为每个 Platform 类添加缺失的方法：

```php
// Windows.php
public function getCompilerFlags(array $options): string
{
    // 返回 MSVC 或 Clang 的编译器标志
}

public function getLinkerFlags(array $options): string
{
    // 返回链接器标志
}
```

### Step 2: 完善 Backend 类（2-3天）

为每个 Backend 类添加完整的方法实现：

```php
// Msvc.php
public function buildFullCompileOptions(array $options): string
{
    // 包括所有 MSVC 特定的编译选项
    // - 宏定义
    // - 优化级别
    // - 警告设置
    // - C++ 标准
    // - Sanitizer
    // - etc.
}
```

### Step 3: 创建适配器层（1天）

在 CompilerBase 中创建适配器方法，桥接旧代码和新架构：

```php
// CompilerBase.php
protected function parseIncludesNew(): string
{
    if ($this->platform === null) {
        return $this->parseIncludesLegacy();
    }
    
    $includePaths = $this->getIncludePaths();
    return $this->platform->getIncludeFlags($includePaths);
}

protected function parseIncludesLegacy(): string
{
    // 旧的实现，保持兼容
}

protected function parseIncludes(): string
{
    return $this->parseIncludesNew();
}
```

### Step 4: 逐个替换方法（3-5天）

按照优先级逐个替换方法：

```php
// 替换前
protected function addWindowsCompilationOption(string &$cmd, bool $link): void
{
    // 100+ 行代码
}

// 替换后
protected function addCompilationOption(string &$cmd, bool $link): void
{
    if ($this->compilerBackend === null) {
        $this->addCompilationOptionLegacy($cmd, $link);
        return;
    }
    
    if (!$link) {
        $cmd .= $this->compilerBackend->buildFullCompileOptions([
            'optimize' => $this->optimizeLevel,
            'debug_info' => $this->debugInfo,
            'sanitize' => $this->sanitize,
            'cpp_std' => $this->cxxStd,
        ]);
    } else {
        $cmd .= $this->compilerBackend->buildFullLinkOptions([
            'debug_info' => $this->debugInfo,
            'no_console' => $this->noConsole,
            'shared' => ($this->buildMode === 'ext'),
        ]);
    }
}
```

### Step 5: 移除旧代码（1-2天）

当所有方法都迁移完成后，删除旧的实现：

```php
// 删除这些方法
- protected function parseWindowsIncludes()
- protected function parseWindowsLdflags()
- protected function parseWindowsLibs()
- protected function detectWindowsPhpLibs()
- protected function addWindowsCompilationOption()
- protected function addWindowsClangCompilationOption()
- protected function addUnixCompilationOption()
```

### Step 6: 测试和验证（2-3天）

1. 单元测试
2. 集成测试
3. 回归测试
4. 性能测试

## 代码示例

### 示例 1：Platform 处理包含路径

**之前（CompilerBase.php）：**
```php
protected function parseWindowsIncludes(): string
{
    $list = [
        $this->getPhpxDir() . '\include',
        $this->getPhpDir() . '\SDK\include',
        // ... 更多路径
    ];
    
    $out = '';
    foreach ($list as $li) {
        $normalizedPath = str_replace('/', '\\', $li);
        $out .= '/I "' . $normalizedPath . '" ';
    }
    
    return $out;
}
```

**之后（使用 Platform）：**
```php
protected function parseIncludes(): string
{
    $includePaths = [
        $this->getPhpxDir() . '/include',
        ...$this->platform->buildPhpSdkIncludePaths($this->getPhpDir()),
    ];
    
    return $this->platform->getIncludeFlags($includePaths);
}
```

### 示例 2：Backend 处理编译选项

**之前（CompilerBase.php）：**
```php
protected function addWindowsCompilationOption(string &$cmd, bool $link): void
{
    if (!$link) {
        $cmd .= ' /DZEND_WIN32';
        $cmd .= ' /DPHP_WIN32';
        $cmd .= ' /DZEND_DEBUG=0';
        
        if ($this->isPhpZts) {
            $cmd .= ' /DZTS';
        }
        
        // ... 100+ 行代码
    }
}
```

**之后（使用 Backend）：**
```php
protected function addCompilationOption(string &$cmd, bool $link): void
{
    if ($this->compilerBackend === null) {
        // 回退到旧逻辑
        return;
    }
    
    if (!$link) {
        $cmd .= $this->compilerBackend->buildFullCompileOptions([
            'optimize' => $this->optimizeLevel,
            'debug_info' => $this->debugInfo,
            'sanitize' => $this->sanitize,
            'cpp_std' => $this->cxxStd,
            'suppressed_warnings' => Constants::MSVC_SUPPRESSED_WARNINGS,
        ]);
    } else {
        $cmd .= $this->compilerBackend->buildFullLinkOptions([
            'debug_info' => $this->debugInfo,
            'no_console' => $this->noConsole,
            'shared' => ($this->buildMode === 'ext'),
        ]);
    }
}
```

### 示例 3：检测 PHP Libs

**之前（CompilerBase.php）：**
```php
protected function detectWindowsPhpLibs(): void
{
    $phpDirs = [
        $this->getPhpDir() . '\SDK\lib',
        $this->getPhpDir() . '\lib',
    ];
    
    // ... 50+ 行检测逻辑
    
    $this->windowsPhpEmbedLib = $embedLibPath;
    $this->windowsPhpCoreLib = $coreLibPath;
    $this->isPhpZts = $isZts;
}
```

**之后（使用 Platform）：**
```php
protected function detectPlatform(): void
{
    $this->isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
    
    if ($this->isWindows) {
        // 使用 Windows Platform 检测
        /** @var Windows $platform */
        $platform = PlatformFactory::create();
        
        $libInfo = $platform->detectPhpLibs($this->getPhpDir());
        
        $this->windowsPhpEmbedLib = $libInfo['embed'];
        $this->windowsPhpCoreLib = $libInfo['core'];
        $this->isPhpZts = $libInfo['is_zts'];
        
        // 重新创建带信息的 Platform 实例
        $this->platform = new Windows(
            phpLibs: [$libInfo['embed'], $libInfo['core']],
            isZts: $libInfo['is_zts']
        );
        
        $this->compilerBackend = CompilerFactory::create($this->platform);
    }
}
```

## 预期收益

### 代码质量
- ✅ CompilerBase.php 减少 ~500 行代码
- ✅ 职责更清晰
- ✅ 更易维护
- ✅ 更易测试

### 可扩展性
- ✅ 添加新平台只需创建新的 Platform 类
- ✅ 添加新编译器只需创建新的 Backend 类
- ✅ 无需修改 CompilerBase

### 可测试性
- ✅ 每个类可以独立测试
- ✅ 易于 Mock
- ✅ 更高的测试覆盖率

## 风险评估

### 低风险
- Platform 和 Backend 层已经存在并工作
- 有完整的回退机制
- 渐进式迁移

### 中风险
- 需要充分测试确保功能一致
- 可能需要调整一些边缘情况

### 缓解措施
1. 保留旧代码作为回退
2. 充分的单元测试
3. 集成测试覆盖所有场景
4. 灰度发布，逐步切换

## 时间估算

| 阶段 | 工作量 | 说明 |
|------|--------|------|
| Phase 1: Platform 增强 | 1-2 天 | 补充缺失方法 |
| Phase 2: Backend 增强 | 2-3 天 | 补充缺失方法 |
| Phase 3: 创建适配器 | 1 天 | 桥接新旧代码 |
| Phase 4: 逐个替换 | 3-5 天 | 迁移核心逻辑 |
| Phase 5: 移除旧代码 | 1-2 天 | 清理冗余代码 |
| Phase 6: 测试验证 | 2-3 天 | 全面测试 |
| **总计** | **10-16 天** | **约 2-3 周** |

## 下一步行动

1. **立即开始：**
   - 完善 Linux 和 macOS Platform 类
   - 完善 GCC 和 Clang Backend 类

2. **本周内：**
   - 创建适配器层
   - 开始替换高优先级方法

3. **下周：**
   - 完成所有方法迁移
   - 编写测试
   - 性能验证

4. **下下周：**
   - 移除旧代码
   - 最终测试
   - 发布新版本

## 总结

这是一个**系统性的重构**，目标是：
- 🎯 完全解耦平台和编译器逻辑
- 🎯 提高代码质量和可维护性
- 🎯 保持向后兼容
- 🎯 降低未来扩展成本

通过**渐进式迁移**和**充分的测试**，可以安全地完成这次重构！
