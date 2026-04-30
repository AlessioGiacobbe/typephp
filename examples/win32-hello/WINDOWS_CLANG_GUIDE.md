# Windows Clang 工具链使用指南

## 📋 概述

PHPX 编译器现在支持在 Windows 下使用 Clang 工具链进行编译和调试，同时保留对 MSVC 的支持。

---

## 🎯 编译器选择优先级

Windows 下的编译器选择遵循以下优先级（从高到低）：

1. **环境变量 `PHPX_CC`** - 用户手动指定
2. **Clang (`clang++`)** - 如果可用，优先使用
3. **MSVC (`cl`)** - 默认 fallback

---

## 🚀 快速开始

### 方法 1：自动检测（推荐）

只需安装 LLVM/Clang，编译器会自动检测并使用：

```powershell
# 安装 LLVM for Windows
# 从 https://releases.llvm.org/ 下载并安装

# 确保 clang++ 在 PATH 中
clang++ --version

# 编译项目（自动使用 Clang）
php bin/compiler.php project.yml
```

输出：
```
Using Clang compiler (clang++)
...
```

---

### 方法 2：强制使用 MSVC

如果需要切换回 MSVC：

```powershell
# 设置环境变量
$env:PHPX_CC = "cl"

# 编译项目
php bin/compiler.php project.yml
```

输出：
```
Using compiler from PHPX_CC: cl
Using MSVC compiler (cl)
...
```

---

### 方法 3：强制使用 Clang

即使有 MSVC，也可以强制使用 Clang：

```powershell
# 设置环境变量
$env:PHPX_CC = "clang++"

# 编译项目
php bin/compiler.php project.yml
```

输出：
```
Using compiler from PHPX_CC: clang++
...
```

---

## 🔧 安装 LLVM/Clang

### 步骤 1：下载 LLVM

访问 [LLVM Releases](https://releases.llvm.org/download.html) 或 [GitHub Releases](https://github.com/llvm/llvm-project/releases)

推荐下载：
- **LLVM-{version}-win64.exe** (Windows 64-bit)

---

### 步骤 2：安装

运行安装程序，建议安装到：
```
C:\Program Files\LLVM
```

**重要：** 勾选 "Add LLVM to the system PATH for all users"

---

### 步骤 3：验证安装

```powershell
# 检查版本
clang++ --version

# 应该看到类似输出
clang version 17.0.6
Target: x86_64-pc-windows-msvc
Thread model: posix
```

---

### 步骤 4：配置 Visual Studio（可选但推荐）

Clang on Windows 需要 Visual Studio 的链接器和库：

```powershell
# 启动 Developer PowerShell
Import-Module "C:\Program Files\Microsoft Visual Studio\2022\Community\Common7\Tools\Microsoft.VisualStudio.DevShell.dll"
Enter-VsDevShell -VsInstallPath "C:\Program Files\Microsoft Visual Studio\2022\Community"
```

---

## 💡 编译器对比

### MSVC vs Clang on Windows

| 特性 | MSVC (`cl`) | Clang (`clang++`) |
|------|------------|------------------|
| **编译器** | Microsoft | LLVM |
| **语法** | MSVC 特有 | GCC 兼容 |
| **警告格式** | C4xxx | 类似 GCC |
| **调试器** | Visual Studio | VS / LLDB / GDB |
| **Sanitizer** | AddressSanitizer | 完整的 Sanitizers |
| **优化** | 优秀 | 优秀 |
| **跨平台** | ❌ Windows only | ✅ 跨平台 |
| **学习曲线** | 中等 | 低（GCC 熟悉者） |
| **链接器** | link.exe | lld-link (推荐) 或 link.exe |

---

## 🔗 链接器选择

Clang on Windows 支持两种链接器：

### 1. lld-link（推荐）

**优势：**
- ✅ **速度快** - 比 link.exe 快 2-5 倍
- ✅ **并行链接** - 更好的多核利用
- ✅ **Clang 原生** - 与 Clang 集成更好
- ✅ **自动检测** - 如果可用会自动使用

**要求：**
- 需要安装 LLVM 组件（包含在 Visual Studio Clang 工具中）

---

### 2. link.exe（fallback）

**优势：**
- ✅ **稳定性好** - 与 Windows SDK 和 CRT 完全兼容
- ✅ **无需额外配置** - Visual Studio 自带

**劣势：**
- ❌ **速度较慢** - 相比 lld-link
- ❌ **并行链接支持弱**

---

### 自动选择逻辑

```
启动编译
  ↓
检测 Clang
  ├─ 1. 检查 PATH 中的 clang++
  │   └─ 找到 → 使用并检测 lld-link
  ├─ 2. 检查 LLVM_HOME 环境变量
  │   └─ 设置且有效 → 使用并检测 lld-link
  └─ 3. 都未找到 → 使用 MSVC
  ↓
检测 lld-link
  ├─ 1. 检查 PATH 中的 lld-link
  │   └─ 找到 → 使用 lld-link
  ├─ 2. 检查 LLVM_HOME/x64/bin/lld-link.exe
  │   └─ 存在 → 使用 lld-link
  └─ 3. 都未找到 → 使用 link.exe
```

编译时会显示：
```
Using Clang compiler (clang++)
Using lld-link linker from LLVM_HOME (faster than link.exe)
```

---

## 🛠️ 编译选项差异

### MSVC 选项

```powershell
cl /std:c++17 /O2 /Wall /MD ...
```

### Clang 选项（Windows）

```powershell
clang++ -std=c++17 -O2 -Wall -MD ...
```

**注意：** Clang on Windows 使用 GCC 风格的选项，但链接时使用 MSVC 的链接器。

---

## 🐛 调试支持

### 使用 Visual Studio 调试

两种编译器都生成 PDB 文件，可以用 Visual Studio 调试：

```powershell
# 启用调试信息
php bin/compiler.php app.php --debug-info

# 在 Visual Studio 中打开生成的 .exe
# 按 F5 开始调试
```

---

### 使用 LLDB 调试（Clang 专属优势）

Clang 原生支持 LLDB：

```powershell
# 编译带调试信息
php bin/compiler.php app.php --debug-info

# 使用 LLDB 调试
lldb app.exe

(lldb) breakpoint set --name main
(lldb) run
(lldb) next
(lldb) frame variable
```

---

## 🔍 Sanitizer 支持

### Clang 的优势

Clang 提供更完整的 Sanitizer 支持：

```powershell
# AddressSanitizer（内存错误检测）
php bin/compiler.php app.php --sanitize=address

# UndefinedBehaviorSanitizer（未定义行为检测）
php bin/compiler.php app.php --sanitize=undefined

# ThreadSanitizer（数据竞争检测）
php bin/compiler.php app.php --sanitize=thread

# 多个 Sanitizer 组合
php bin/compiler.php app.php --sanitize=address,undefined
```

### MSVC 的限制

MSVC 目前只支持 AddressSanitizer：

```powershell
# MSVC 仅支持 address
php bin/compiler.php app.php --sanitize=address
```

---

## ⚙️ 环境变量

### LLVM_HOME（推荐）

指定 LLVM/Clang 的安装路径：

```powershell
# 设置 LLVM_HOME（临时，当前会话）
$env:LLVM_HOME = "C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Tools\Llvm"

# 设置 LLVM_HOME（永久，用户级别）
[Environment]::SetEnvironmentVariable("LLVM_HOME", "C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Tools\Llvm", "User")

# 验证
php bin/compiler.php app.php
```

**优势：**
- ✅ 无需修改系统 PATH
- ✅ 灵活配置不同版本
- ✅ 避免硬编码路径

---

### PHPX_CC

指定使用的编译器：

```powershell
# 使用 Clang
$env:PHPX_CC = "clang++"

# 使用 MSVC
$env:PHPX_CC = "cl"

# 使用完整路径
$env:PHPX_CC = "C:\Program Files\LLVM\bin\clang++.exe"
```

---

### PATH

确保编译器在 PATH 中：

```powershell
# 添加 LLVM 到 PATH
$env:Path = "C:\Program Files\LLVM\bin;$env:Path"

# 添加 Visual Studio 工具到 PATH
Import-Module "C:\Program Files\Microsoft Visual Studio\2022\Community\Common7\Tools\Microsoft.VisualStudio.DevShell.dll"
Enter-VsDevShell -VsInstallPath "C:\Program Files\Microsoft Visual Studio\2022\Community"
```

---

## 📊 性能对比

### 编译速度

| 场景 | MSVC | Clang |
|------|------|-------|
| 首次编译 | 快 | 稍慢 |
| 增量编译 | 中等 | 快 |
| 并行编译 | 好 | 优秀 |

---

### 运行时性能

| 优化级别 | MSVC | Clang |
|---------|------|-------|
| -O0 | 相同 | 相同 |
| -O2 | 优秀 | 优秀 |
| -O3 | 优秀 | 略优 |

**注意：** 实际性能差异很小，取决于具体代码。

---

## 🎯 使用场景

### 推荐使用 Clang 的场景

1. ✅ **跨平台开发** - 代码需要在 Linux/macOS 上编译
2. ✅ **使用 Sanitizers** - 需要全面的内存/线程检测
3. ✅ **GCC 兼容性** - 熟悉 GCC 命令行
4. ✅ **开源项目** - 更多开发者可以使用
5. ✅ **学习和研究** - 更好的错误信息

---

### 推荐使用 MSVC 的场景

1. ✅ **纯 Windows 项目** - 不需要跨平台
2. ✅ **Visual Studio 集成** - 深度使用 VS 功能
3. ✅ **现有项目** - 已经使用 MSVC
4. ✅ **特定 MSVC 特性** - 需要 MSVC 独有功能

---

## 🐛 常见问题

### Q1: 如何确认当前使用的是哪个编译器？

A: 编译时会显示：

```
Using Clang compiler (clang++)
```

或

```
Using MSVC compiler (cl)
```

---

### Q2: Clang on Windows 需要什么依赖？

A: 
- LLVM/Clang 编译器
- Visual Studio Build Tools（提供链接器和库）
- Windows SDK

---

### Q3: 可以在 MSVC 和 Clang 之间切换吗？

A: 可以！使用 `PHPX_CC` 环境变量：

```powershell
# 切换到 Clang
$env:PHPX_CC = "clang++"
php bin/compiler.php app.php

# 切换到 MSVC
$env:PHPX_CC = "cl"
php bin/compiler.php app.php
```

---

### Q4: Clang 生成的代码可以和 MSVC 混用吗？

A: **不建议**。虽然都生成 COFF 格式的目标文件，但：
- ABI 可能不同
- CRT 库可能有冲突
- 调试信息格式不同

**建议：** 整个项目使用同一种编译器。

---

### Q5: 为什么 Clang 是首选？

A: 
1. **更好的错误信息** - 更清晰、更易读
2. **更快的编译速度** - 特别是增量编译
3. **完整的 Sanitizers** - AddressSanitizer, UBSan, TSan 等
4. **跨平台兼容** - 同样的代码可以在 Linux/macOS 编译
5. **活跃的社区** - LLVM 项目发展迅速

---

## 📝 配置示例

### project.yml - 使用 Clang

```yaml
name: my-app
build-mode: bin
cxx-std: c++17

# Clang 特定的编译选项
cxx-flags:
  - -Wall
  - -Wextra
  - -Wpedantic

sources:
  - src/*.php
```

编译：
```powershell
# 自动使用 Clang（如果已安装）
php bin/compiler.php project.yml

# 或强制使用
$env:PHPX_CC = "clang++"
php bin/compiler.php project.yml
```

---

### project.yml - 使用 MSVC

```yaml
name: my-app
build-mode: bin
cxx-std: c++17

# MSVC 特定的编译选项
cxx-flags:
  - /W4
  - /permissive-

sources:
  - src/*.php
```

编译：
```powershell
# 自动使用 MSVC（如果没有 Clang）
php bin/compiler.php project.yml

# 或强制使用
$env:PHPX_CC = "cl"
php bin/compiler.php project.yml
```

---

## 🔗 相关资源

- [LLVM Download Page](https://releases.llvm.org/download.html)
- [Clang Documentation](https://clang.llvm.org/docs/)
- [AddressSanitizer](https://clang.llvm.org/docs/AddressSanitizer.html)
- [Visual Studio Build Tools](https://visualstudio.microsoft.com/downloads/#build-tools-for-visual-studio-2022)

---

## 🎉 总结

### 核心优势

1. ✅ **灵活性** - 可以在 MSVC 和 Clang 之间选择
2. ✅ **兼容性** - 保留 MSVC 支持，不影响现有项目
3. ✅ **现代化** - Clang 提供更好的工具和诊断
4. ✅ **跨平台** - 为未来的跨平台支持做准备
5. ✅ **易于切换** - 通过环境变量轻松切换

### 推荐实践

- 🆕 新项目 → 优先使用 Clang
- 🔄 现有项目 → 可以继续使用 MSVC
- 🧪 测试/调试 → 使用 Clang + Sanitizers
- 🚀 生产环境 → 根据团队熟悉度选择

希望这个指南能帮助您充分利用 Windows Clang 工具链！
