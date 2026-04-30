# Windows Clang 支持测试指南

## ✅ 已完成的修改

### 1. 代码实现

#### CompilerBase.php 修改

**文件位置：** `D:\workspace\compiler\src\Php\CompilerBase.php`

**主要修改：**

1. **detectPlatform() 方法** (第 298-327 行)
   - 添加智能编译器检测
   - 优先级：PHPX_CC 环境变量 > Clang > MSVC
   - 显示当前使用的编译器信息

2. **isClangAvailable() 方法** (第 329-370 行)
   - 检测 PATH 中的 clang++
   - 自动搜索 Visual Studio LLVM 路径
   - 支持 VS 2019 和 VS 2022 所有版本
   - 找到后自动添加到 PATH

3. **addCompilationOption() 方法** (第 2410-2425 行)
   - 根据编译器类型分发到不同的处理方法
   - 支持 MSVC 和 Clang

4. **addWindowsClangCompilationOption() 方法** (第 2543-2647 行)
   - 新增方法，处理 Windows Clang 编译选项
   - 使用 GCC 风格的编译选项（-std, -O, -Wall）
   - 链接时使用 MSVC 链接器选项
   - 完整的 Sanitizer 支持

---

## 🧪 测试步骤

### 前置条件

您需要安装以下之一：

#### 选项 1：Visual Studio LLVM 组件（推荐）

1. 打开 **Visual Studio Installer**
2. 点击 **修改** 您的 Visual Studio 安装
3. 切换到 **单个组件** 标签
4. 搜索并勾选：
   - ✅ **Clang compiler for Windows**
   - ✅ **C++ Clang tools for Windows**
5. 点击 **修改** 开始安装

#### 选项 2：独立 LLVM 安装

1. 访问 https://releases.llvm.org/download.html
2. 下载 **LLVM-{version}-win64.exe**
3. 运行安装程序
4. **重要：** 勾选 "Add LLVM to the system PATH"

---

### 测试 1：检查 Clang 安装

运行检查脚本：

```cmd
cd D:\workspace\compiler
check-clang.bat
```

或使用 PowerShell：

```powershell
cd D:\workspace\compiler
.\check-clang.ps1
```

**预期输出：**

如果 Clang 已安装：
```
=== Checking Clang Installation ===

1. Checking PATH for clang++:
Found in PATH
clang version 17.0.6

2. Checking Visual Studio paths:
Found: VS 2022 Community
clang version 17.0.6

=== Check Complete ===
```

如果未安装：
```
Not found in PATH
Not found in Visual Studio directories

To install LLVM for Visual Studio:
1. Open Visual Studio Installer
2. Modify your installation
...
```

---

### 测试 2：编译器检测

运行 PHP 测试脚本：

```cmd
php test-compiler-detection.php
```

**预期输出：**

如果使用 Clang：
```
=== Testing Compiler Detection ===

Platform Detection:
  isWindows: true
  cppCompiler: clang++
  cxxStd: c++17

Clang Detection Test:
  isClangAvailable: true

✓ Clang is available and will be used by default

=== Test Complete ===
```

如果使用 MSVC：
```
Platform Detection:
  isWindows: true
  cppCompiler: cl
  cxxStd: c++17

Clang Detection Test:
  isClangAvailable: false

✗ Clang not found, will use MSVC

=== Test Complete ===
```

---

### 测试 3：实际编译测试

#### 测试 3a：使用 Clang 编译

```cmd
# 确保 Clang 可用
php test-compiler-detection.php

# 编译测试文件
php bin/compiler.php test-clang-simple.php

# 运行生成的可执行文件
test-clang-simple.exe
```

**预期输出：**
```
Hello from PHPX!
Testing Clang compiler support...
PHP Version: 8.x.x
Sum of [1,2,3,4,5] = 15
Test completed successfully!
```

---

#### 测试 3b：强制使用 MSVC

```cmd
# 设置环境变量强制使用 MSVC
set PHPX_CC=cl

# 编译
php bin/compiler.php test-clang-simple.php

# 运行
test-clang-simple.exe
```

---

#### 测试 3c：强制使用 Clang

```cmd
# 设置环境变量强制使用 Clang
set PHPX_CC=clang++

# 编译
php bin/compiler.php test-clang-simple.php

# 运行
test-clang-simple.exe
```

---

### 测试 4：Sanitizer 测试（仅 Clang）

```cmd
# 使用 AddressSanitizer 编译
php bin/compiler.php test-clang-simple.php --sanitize=address

# 运行（如果有内存错误会报告）
test-clang-simple.exe
```

---

## 📊 验证清单

完成后，请确认以下功能：

- [ ] Clang 正确安装在系统中
- [ ] `isClangAvailable()` 能检测到 Clang
- [ ] 默认情况下优先使用 Clang（如果可用）
- [ ] 可以通过 `PHPX_CC` 环境变量切换编译器
- [ ] Clang 编译的程序可以正常运行
- [ ] MSVC 仍然可以正常工作
- [ ] Sanitizer 选项在 Clang 下工作
- [ ] 编译时显示正确的编译器信息

---

## 🐛 故障排除

### 问题 1：Clang 未被检测到

**症状：** `isClangAvailable()` 返回 false

**解决：**
1. 确认 Clang 已安装
2. 检查 PATH 环境变量
3. 重启命令行窗口
4. 手动添加 Clang 到 PATH：
   ```cmd
   set PATH=C:\Program Files\Microsoft Visual Studio\2022\Community\VC\Tools\Llvm\x64\bin;%PATH%
   ```

---

### 问题 2：编译失败

**症状：** 编译时出现错误

**检查：**
1. 确认使用的是正确的编译器
2. 检查 Visual Studio Developer Command Prompt
3. 查看详细的错误信息

**尝试：**
```cmd
# 清除缓存后重新编译
del /s /q *.o 2>nul
del /s /q *.obj 2>nul
php bin/compiler.php test.php
```

---

### 问题 3：链接错误

**症状：** 编译成功但链接失败

**原因：** Clang on Windows 需要 MSVC 的链接器

**解决：**
1. 确保 Visual Studio Build Tools 已安装
2. 使用 Developer Command Prompt
3. 检查 Windows SDK 是否安装

---

## 📝 下一步

如果测试成功，您可以：

1. ✅ 开始在项目中使用 Clang
2. ✅ 利用 Clang 的 Sanitizers 进行调试
3. ✅ 享受更好的错误信息和诊断
4. ✅ 为跨平台开发做准备

---

## 🔗 相关文档

- [WINDOWS_CLANG_GUIDE.md](./examples/win32-hello/WINDOWS_CLANG_GUIDE.md) - 完整的使用指南
- [YAML_CONFIG_NAMING_CONVENTION.md](./examples/win32-hello/YAML_CONFIG_NAMING_CONVENTION.md) - YAML 配置规范
- [CONFIG_PRIORITY_RULES.md](./examples/win32-hello/CONFIG_PRIORITY_RULES.md) - 配置优先级规则

---

## 💡 提示

**快速切换编译器：**

```cmd
# 使用 Clang
set PHPX_CC=clang++
php bin/compiler.php app.php

# 使用 MSVC
set PHPX_CC=cl
php bin/compiler.php app.php

# 自动选择（默认）
set PHPX_CC=
php bin/compiler.php app.php
```

**永久设置（系统环境变量）：**

```cmd
# 设置为 Clang
setx PHPX_CC "clang++"

# 设置为 MSVC
setx PHPX_CC "cl"

# 删除设置（自动选择）
setx PHPX_CC ""
```

---

祝测试顺利！如有问题，请查看详细文档或报告 bug。
