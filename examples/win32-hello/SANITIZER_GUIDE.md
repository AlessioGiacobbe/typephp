# AddressSanitizer 使用指南

## 📋 概述

AddressSanitizer (ASan) 是一个快速的内存错误检测工具，可以检测：
- 堆缓冲区溢出/下溢
- 栈缓冲区溢出/下溢
- 全局缓冲区溢出/下溢
- 释放后使用（Use-after-free）
- 返回后使用（Use-after-return）
- 重复释放（Double-free）
- 内存泄漏

---

## 🚀 快速开始

### Windows (MSVC)

```powershell
# 启用 AddressSanitizer
php bin/compiler.php your-app.php --sanitize=address

# 或简写
php bin/compiler.php your-app.php --sanitize=addr
```

**要求：**
- Visual Studio 2019 16.9+ 或 Visual Studio 2022
- MSVC 编译器版本 19.29+

### Linux/macOS (GCC/Clang)

```bash
# 单个 sanitizer
php bin/compiler.php your-app.php --sanitize=address

# 多个 sanitizer（用逗号分隔）
php bin/compiler.php your-app.php --sanitize=address,undefined

# 可用的 sanitizer 类型：
# - address: 地址错误检测
# - undefined: 未定义行为检测
# - thread: 线程竞争检测
# - memory: 未初始化内存读取检测
# - leak: 内存泄漏检测
```

---

## 🔍 示例输出

当检测到内存错误时，AddressSanitizer 会输出详细的错误信息：

```
=================================================================
==12345==ERROR: AddressSanitizer: heap-buffer-overflow on address 0x602000000010
READ of size 4 at 0x602000000010 thread T0
    #0 0x7ff6abc12345 in main D:\workspace\compiler\examples\test.php:10
    #1 0x7ff6abc67890 in __scrt_common_main_seh

0x602000000010 is located 0 bytes to the right of 16-byte region [0x602000000000,0x602000000010)
allocated by thread T0 here:
    #0 0x7ff6abc98765 in operator new[] 
    #1 0x7ff6abc12340 in main D:\workspace\compiler\examples\test.php:8

SUMMARY: AddressSanitizer: heap-buffer-overflow
=================================================================
```

---

## 💡 使用建议

### 1. 开发阶段启用

在开发和测试阶段启用 AddressSanitizer，可以帮助您尽早发现内存错误：

```powershell
# 编译带 AddressSanitizer 的版本
php bin/compiler.php debug-test.php --sanitize=address --no-console

# 运行测试
.\debug-test.exe
```

### 2. 不要与优化同时使用

AddressSanitizer 会降低程序性能（约 2x），建议：
- 开发时：`-O0 --sanitize=address`
- 发布时：`-O2`（不使用 sanitizer）

### 3. 结合调试信息使用

```powershell
# 同时启用调试信息和 AddressSanitizer
php bin/compiler.php app.php --debug-info --sanitize=address
```

这样可以在错误报告中看到源代码行号。

---

## 🛠️ 常见用例

### 检测数组越界

```php
<?php

function test_buffer_overflow()
{
    $arr = [1, 2, 3, 4, 5];
    
    // 这会触发 AddressSanitizer 错误
    $value = $arr[10];  // 越界访问
    
    return $value;
}

function main()
{
    test_buffer_overflow();
}
```

### 检测释放后使用

```php
<?php

class TestClass
{
    public function __destruct()
    {
        // 对象被销毁
    }
}

function test_use_after_free()
{
    $obj = new TestClass();
    unset($obj);  // 对象被销毁
    
    // 如果继续访问 $obj，可能触发错误
    // （PHP 的垃圾回收机制通常会防止这种情况）
}

function main()
{
    test_use_after_free();
}
```

### 检测内存泄漏

```php
<?php

function test_memory_leak()
{
    // 在 C++ 层分配的内存如果没有正确释放
    // AddressSanitizer 会检测到
}

function main()
{
    test_memory_leak();
}
```

---

## ⚙️ 高级配置

### 环境变量

AddressSanitizer 支持通过环境变量进行配置：

#### Windows

```powershell
# 设置 ASan 选项
$env:ASAN_OPTIONS = "detect_leaks=1:print_stats=1"

# 运行程序
.\your-app.exe
```

#### Linux/macOS

```bash
# 设置 ASan 选项
export ASAN_OPTIONS="detect_leaks=1:print_stats=1"

# 运行程序
./your-app
```

### 常用选项

| 选项 | 说明 | 默认值 |
|------|------|--------|
| `detect_leaks` | 检测内存泄漏 | 1 |
| `print_stats` | 打印统计信息 | 0 |
| `abort_on_error` | 错误时中止程序 | 0 |
| `log_path` | 日志文件路径 | stderr |
| `halt_on_error` | 第一个错误后停止 | 1 |

**示例：**

```powershell
# Windows
$env:ASAN_OPTIONS = "detect_leaks=1:print_stats=1:log_path=asan.log"
.\your-app.exe

# Linux/macOS
export ASAN_OPTIONS="detect_leaks=1:print_stats=1:log_path=asan.log"
./your-app
```

---

## 🔧 平台特定说明

### Windows (MSVC)

**限制：**
- 仅支持 `address` sanitizer
- 需要 Visual Studio 2019 16.9+ 或更新版本
- 可能与某些第三方库不兼容

**注意事项：**
- AddressSanitizer 会增加可执行文件大小
- 运行时性能下降约 2x
- 内存使用增加约 2-3x

### Linux (GCC/Clang)

**支持的 sanitizer：**
- `address` - 地址错误
- `undefined` - 未定义行为
- `thread` - 线程竞争
- `memory` - 未初始化内存
- `leak` - 内存泄漏

**组合使用：**

```bash
# AddressSanitizer + UndefinedBehaviorSanitizer
php bin/compiler.php app.php --sanitize=address,undefined

# ThreadSanitizer（不能与其他 sanitizer 同时使用）
php bin/compiler.php app.php --sanitize=thread
```

### macOS (Clang)

与 Linux 类似，但需要注意：
- MemorySanitizer 在 macOS 上可能不可用
- 建议使用 Homebrew 安装最新版本的 Clang

```bash
brew install llvm
export CC=/usr/local/opt/llvm/bin/clang
export CXX=/usr/local/opt/llvm/bin/clang++
php bin/compiler.php app.php --sanitize=address
```

---

## 🐛 故障排除

### Q: 编译时提示不支持 sanitizer？

A: 检查编译器版本：

```powershell
# Windows
cl

# Linux
gcc --version
clang --version
```

确保使用支持 sanitizer 的版本。

### Q: 运行时出现 "Sanitizer CHECK failed"？

A: 这可能是由于：
1. 库冲突 - 确保所有库都用相同的 sanitizer 编译
2. 不兼容的选项 - 尝试移除其他优化选项
3. 系统限制 - 检查是否有足够的内存

### Q: AddressSanitizer 报告误报？

A: 可能的原因：
1. 第三方库的问题 - 考虑抑制特定模块的检查
2. 已知的无害问题 - 使用 `__attribute__((no_sanitize))` 禁用特定函数

```cpp
// 在 C++ 代码中
__attribute__((no_sanitize("address")))
void safe_function() {
    // 这个函数不会被 ASan 检查
}
```

### Q: 性能太慢怎么办？

A: 
1. 只在调试时使用 sanitizer
2. 发布版本禁用 sanitizer
3. 使用 `-O1` 而不是 `-O0`（仍保持较好的检测能力）

---

## 📊 性能影响

| 配置 | 速度 | 内存 | 适用场景 |
|------|------|------|----------|
| 无 sanitizer, -O2 | 100% | 100% | 生产环境 |
| -fsanitize=address, -O0 | ~50% | ~200% | 开发调试 |
| -fsanitize=address, -O1 | ~60% | ~180% | 测试环境 |
| -fsanitize=undefined, -O0 | ~80% | ~120% | 轻量级检查 |

---

## 🎯 最佳实践

1. **持续集成中启用**
   ```yaml
   # .github/workflows/test.yml
   - name: Compile with ASan
     run: php bin/compiler.php tests/*.php --sanitize=address
   
   - name: Run tests
     run: ./run-tests.sh
   ```

2. **定期扫描**
   - 每周运行一次带 sanitizer 的完整测试套件
   - 修复所有报告的问题

3. **结合其他工具**
   - Valgrind（Linux）
   - Dr. Memory（Windows）
   - Static analyzers（静态分析器）

4. **文档化已知问题**
   - 记录无法立即修复的 sanitizer 警告
   - 说明为什么这些警告可以忽略

---

## 📚 相关资源

- [AddressSanitizer 官方文档](https://github.com/google/sanitizers/wiki/AddressSanitizer)
- [MSVC AddressSanitizer](https://docs.microsoft.com/cpp/sanitizers/asan)
- [GCC Sanitizers](https://gcc.gnu.org/onlinedocs/gcc/Instrumentation-Options.html)
- [Clang Sanitizers](https://clang.llvm.org/docs/UsersManual.html#controlling-code-generation)

---

## 🔗 编译器命令参考

```powershell
# Windows - 基础用法
php bin/compiler.php app.php --sanitize=address

# Windows - 结合其他选项
php bin/compiler.php app.php --sanitize=address --debug-info --no-console

# Linux/macOS - 单个 sanitizer
php bin/compiler.php app.php --sanitize=address

# Linux/macOS - 多个 sanitizer
php bin/compiler.php app.php --sanitize=address,undefined

# Linux/macOS - 内存泄漏检测
php bin/compiler.php app.php --sanitize=leak
```

希望这个指南能帮助您有效使用 AddressSanitizer 来检测和修复内存错误！
