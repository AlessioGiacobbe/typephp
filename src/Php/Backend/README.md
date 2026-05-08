# 编译器和平台抽象层

## 目录结构

```
src/Php/
├── Backend/          # 编译器后端抽象
│   ├── CompilerBackend.php    # 编译器抽象基类
│   ├── CompilerFactory.php    # 编译器工厂（自动检测）
│   ├── Msvc.php               # MSVC 编译器实现 ✅
│   ├── Gcc.php                # GCC 编译器实现 ✅
│   ├── Clang.php              # Clang 编译器实现 ✅
│   ├── example_usage.php      # 使用示例
│   └── README.md              # 本文档
└── Platform/         # 平台抽象
    ├── PlatformBase.php       # 平台抽象基类
    ├── PlatformFactory.php    # 平台工厂（自动检测）
    ├── Windows.php            # Windows 平台实现 ✅
    ├── Linux.php              # Linux 平台实现 ✅
    └── Macos.php              # macOS 平台实现 ✅
```

## 设计理念

### 1. 平台抽象 (Platform)

**职责：**
- 处理不同操作系统的差异
- 提供统一的路径、文件扩展名、命令行参数格式
- 封装平台特定的配置和选项

**核心方法：**
- `getIncludeFlags()` - 获取包含路径参数
- `getLibraryPathFlags()` - 获取库路径参数
- `getLibraryFlags()` - 获取库文件参数
- `getObjectExtension()` - 获取对象文件扩展名
- `getExecutableExtension()` - 获取可执行文件扩展名

### 2. 编译器后端 (Backend)

**职责：**
- 处理不同编译器的差异
- 生成编译和链接命令
- 封装编译器特定的选项和标志

**核心方法：**
- `compileFile()` - 编译单个文件
- `linkObjects()` - 链接目标文件
- `buildCompileCommand()` - 构建完整编译命令
- `buildLinkCommand()` - 构建完整链接命令

## 使用示例

### 基本用法

```php
use PhpAot\Php\Platform\Windows;
use PhpAot\Php\Backend\Msvc;

// 创建平台实例
$platform = new Windows(
    phpLibs: ['php8embed.lib', 'php8ts.lib'],
    isZts: true
);

// 创建编译器后端
$compiler = new Msvc($platform);

// 构建编译命令
$compileCmd = $compiler->buildCompileCommand(
    'source.cpp',
    'source.obj',
    [
        'optimize' => 2,
        'cpp_std' => 'c++17',
    ]
);

// 构建链接命令
$linkCmd = $compiler->buildLinkCommand(
    ['source.obj'],
    'output.exe',
    [
        'debug' => true,
        'no_console' => false,
    ]
);
```

### 跨平台支持

```php
use PhpAot\Php\Platform\Linux;
use PhpAot\Php\Platform\Macos;
use PhpAot\Php\Backend\Gcc;

// 自动检测当前平台
if ((new Windows())->isCurrent()) {
    $platform = new Windows();
    $compiler = new Msvc($platform);
} elseif ((new Linux())->isCurrent()) {
    $platform = new Linux();
    $compiler = new Gcc($platform);
} elseif ((new Macos())->isCurrent()) {
    $platform = new Macos();
    $compiler = new Gcc($platform);  // macOS 通常也用 GCC/Clang
}
```

## 优势

### 1. 关注点分离
- **Platform**: 只关心操作系统差异
- **Backend**: 只关心编译器差异
- 两者独立，可以任意组合

### 2. 易于扩展
- 添加新平台：继承 `PlatformBase`
- 添加新编译器：继承 `CompilerBackend`
- 无需修改现有代码

### 3. 易于测试
- 每个类职责单一
- 可以独立单元测试
- 便于 Mock 和 stub

### 4. 代码复用
- 平台相关逻辑集中管理
- 编译器相关逻辑集中管理
- 避免重复代码

## 迁移计划

### 阶段 1：创建抽象层（✅ 已完成）
- ✅ 创建 Platform 抽象和实现
  - ✅ PlatformBase.php
  - ✅ Windows.php
  - ✅ Linux.php
  - ✅ Macos.php
  - ✅ PlatformFactory.php
- ✅ 创建 Backend 抽象和实现
  - ✅ CompilerBackend.php
  - ✅ Msvc.php
  - ✅ Gcc.php
  - ✅ Clang.php
  - ✅ CompilerFactory.php
- ✅ 创建使用示例和文档
  - ✅ example_usage.php
  - ✅ README.md

### 阶段 2：重构 CompilerBase
- 将平台相关代码迁移到 Platform 类
- 将编译器相关代码迁移到 Backend 类
- CompilerBase 作为协调者使用这些类

### 阶段 3：清理和优化
- 删除冗余代码
- 更新文档
- 完善测试

## 下一步工作

### ✅ 已完成
1. **完成 Backend 实现**
   - ✅ 创建 `Gcc.php`
   - ✅ 创建 `Clang.php`
   - ✅ 创建 `CompilerFactory.php`（自动检测）

2. **完成 Platform 实现**
   - ✅ 创建所有平台实现
   - ✅ 创建 `PlatformFactory.php`（自动检测）

3. **文档和示例**
   - ✅ 创建详细 README
   - ✅ 创建使用示例
   - ✅ 创建迁移指南（MIGRATION_GUIDE.md）
   - ✅ 创建集成测试（test_integration.php）

4. **CompilerBase 集成**
   - ✅ 添加新架构属性
   - ✅ 添加自动初始化逻辑
   - ✅ 保持向后兼容

### 🔄 进行中
5. **重构 CompilerBase**
   - ⏳ 使用新的 Platform 和 Backend 类
   - ⏳ 保持向后兼容
   - ⏳ 逐步迁移现有代码
   - 📖 详见 [MIGRATION_GUIDE.md](MIGRATION_GUIDE.md)

### 📋 待办
6. **添加单元测试**
   - ⏳ 测试所有 Platform 实现
   - ⏳ 测试所有 Backend 实现
   - ⏳ 测试工厂类
   - ⏳ 测试集成场景

7. **文档完善**
   - ⏳ 添加更多使用示例
   - ⏳ 编写迁移指南
   - ⏳ 更新 API 文档
