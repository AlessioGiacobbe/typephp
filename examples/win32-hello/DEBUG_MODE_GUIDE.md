# 调试模式使用指南

## 📋 概述

`--debug-info` 参数用于启用调试模式，它会自动：
1. **禁用优化**（`-O0` 或 `/Od`）
2. **添加调试信息**（`-g` 或 `/Zi`）
3. **生成符号文件**（`.pdb` 文件，Windows）

这使得您可以使用调试器（如 GDB、LLDB、Visual Studio）来调试编译后的程序。

---

## 🚀 快速开始

### Windows (MSVC)

```powershell
# 启用调试模式
php bin/compiler.php your-app.php --debug-info

# 结合其他选项
php bin/compiler.php your-app.php --debug-info --no-console

# 运行程序
.\your-app.exe

# 使用 Visual Studio 调试
# 1. 打开 your-app.exe
# 2. 按 F5 启动调试
# 3. 设置断点，查看变量
```

### Linux/macOS (GCC/Clang)

```bash
# 启用调试模式
php bin/compiler.php your-app.php --debug-info

# 结合其他选项
php bin/compiler.php your-app.php --debug-info --sanitize=address

# 运行程序
./your-app

# 使用 GDB 调试
gdb ./your-app
(gdb) break main
(gdb) run
(gdb) next
(gdb) print variable_name
```

---

## 🔍 调试模式 vs 发布模式

| 特性 | 调试模式 (`--debug-info`) | 发布模式 (默认) |
|------|--------------------------|----------------|
| 优化级别 | `-O0` / `/Od` (禁用) | `-O2` / `/O2` (最大速度) |
| 调试信息 | ✅ 生成 (`-g` / `/Zi`) | ❌ 不生成 |
| 符号文件 | ✅ 生成 (`.pdb`) | ❌ 不生成 |
| 执行速度 | 较慢 | 快 |
| 文件大小 | 较大 | 较小 |
| 适用场景 | 开发、调试 | 生产环境 |

---

## 💡 使用示例

### 1. 基本调试

```powershell
# 编译带调试信息的版本
php bin/compiler.php debug-test.php --debug-info --no-console

# 在 Visual Studio 中调试
# - 打开 debug-test.exe
# - 设置断点
# - 按 F5 运行
# - 查看变量值、调用堆栈
```

### 2. 结合 AddressSanitizer

```powershell
# 同时启用调试信息和 AddressSanitizer
php bin/compiler.php asan-test.php --debug-info --sanitize=address --no-console

# 这样可以：
# - 看到源代码行号
# - 检测内存错误
# - 获得详细的错误报告
```

### 3. GDB 调试 (Linux)

```bash
# 编译
php bin/compiler.php app.php --debug-info

# 启动 GDB
gdb ./app

# GDB 常用命令
(gdb) break main          # 在 main 函数设置断点
(gdb) break filename:10   # 在第 10 行设置断点
(gdb) run                 # 运行程序
(gdb) next                # 执行下一行
(gdb) step                # 进入函数
(gdb) print var           # 打印变量值
(gdb) backtrace           # 显示调用堆栈
(gdb) continue            # 继续执行
(gdb) quit                # 退出
```

### 4. LLDB 调试 (macOS)

```bash
# 编译
php bin/compiler.php app.php --debug-info

# 启动 LLDB
lldb ./app

# LLDB 常用命令
(lldb) breakpoint set --name main
(lldb) run
(lldb) next
(lldb) step
(lldb) frame variable
(lldb) thread backtrace
(lldb) continue
(lldb) quit
```

---

## 🛠️ 高级技巧

### 1. 条件断点

```gdb
# GDB
(gdb) break main if x > 10

# LLDB
(lldb) breakpoint set --name main --condition 'x > 10'
```

### 2. 观察点（Watchpoint）

```gdb
# 当变量改变时中断
(gdb) watch my_variable
(gdb) continue
```

### 3. 检查内存

```gdb
# GDB
(gdb) x/10x &array      # 查看数组的前 10 个元素
(gdb) p *ptr@10         # 查看指针指向的 10 个元素

# LLDB
(lldb) memory read --format x --count 10 &array
```

### 4. 多线程调试

```gdb
# GDB
(gdb) info threads      # 查看所有线程
(gdb) thread 2          # 切换到线程 2
(gdb) thread apply all bt  # 所有线程的堆栈

# LLDB
(lldb) thread list
(lldb) thread select 2
(lldb) thread backtrace all
```

---

## 📊 性能对比

### 编译时间

| 模式 | 相对时间 |
|------|---------|
| 发布模式 (-O2) | 100% |
| 调试模式 (-O0 -g) | 80% (更快) |

### 运行时性能

| 模式 | 相对速度 | 内存使用 |
|------|---------|---------|
| 发布模式 (-O2) | 100% | 100% |
| 调试模式 (-O0 -g) | 30-50% | 120-150% |

### 文件大小

| 模式 | 可执行文件 | 符号文件 |
|------|-----------|---------|
| 发布模式 | 小 | 无 |
| 调试模式 | 大 | .pdb (Windows) / 嵌入 (Unix) |

---

## 🔧 平台特定说明

### Windows (MSVC)

**生成的文件：**
- `app.exe` - 可执行文件
- `app.pdb` - 程序数据库文件（包含调试信息）

**调试工具：**
- Visual Studio 2022（推荐）
- WinDbg
- Visual Studio Code + C++ 扩展

**注意事项：**
- PDB 文件必须与 EXE 在同一目录
- 不要删除 PDB 文件，否则无法调试
- 可以使用 `/DEBUG:FASTLINK` 加快链接速度

### Linux (GCC)

**调试信息：**
- 默认嵌入到可执行文件中
- 也可以使用 `-ggdb` 生成 GDB 专用信息

**调试工具：**
- GDB
- DDD (GDB 图形界面)
- Visual Studio Code + C++ 扩展

**优化选项：**
```bash
# 基本调试
-g

# GDB 专用
-ggdb

# 更多详细信息
-g3

# 仅调试宏
-ggdb3
```

### macOS (Clang)

**调试信息：**
- 默认使用 DWARF 格式
- 嵌入到可执行文件中

**调试工具：**
- LLDB（默认）
- Xcode
- Visual Studio Code + C++ 扩展

**特殊选项：**
```bash
# 生成 dSYM 文件（分离调试信息）
-g -Wl,-S

# 保留所有符号
-g -fno-eliminate-unused-debug-types
```

---

## 🐛 常见问题

### Q: 为什么调试模式下程序运行很慢？

A: 因为禁用了所有优化（`-O0`）。这是正常的，调试模式的目标是便于调试，而不是性能。

**解决方案：**
- 只在调试时使用 `--debug-info`
- 发布时使用 `-O2` 或 `-O3`

### Q: 调试器看不到某些变量？

A: 可能的原因：
1. 变量被优化掉了（即使使用 `-O0`）
2. 变量超出了作用域
3. 调试信息不完整

**解决方案：**
```bash
# 使用更详细的调试信息
php bin/compiler.php app.php --debug-info

# 或者在 GCC/Clang 上
# 手动添加 -g3
```

### Q: 如何调试 Release 版本？

A: 不推荐，但可以：
```bash
# 保留调试信息但启用优化
php bin/compiler.php app.php -O2 --debug-info
```

注意：优化可能会使调试变得困难，因为代码可能被重排或内联。

### Q: PDB 文件太大怎么办？

A: 
```powershell
# 使用增量链接
/link /INCREMENTAL

# 或使用 FASTLINK
/link /DEBUG:FASTLINK
```

### Q: 如何在没有调试器的情况下调试？

A: 
1. 添加日志输出
2. 使用消息框显示变量值
3. 使用 AddressSanitizer 检测错误
4. 查看核心转储（core dump）

---

## 🎯 最佳实践

### 1. 开发工作流

```bash
# 日常开发
php bin/compiler.php app.php --debug-info

# 运行测试
./app

# 调试问题
gdb ./app

# 准备发布
php bin/compiler.php app.php -O2
```

### 2. 持续集成

```yaml
# .github/workflows/test.yml
- name: Debug Build
  run: php bin/compiler.php tests/*.php --debug-info

- name: Run Tests with GDB
  run: |
    gdb -batch -ex "run" -ex "bt" ./test_app
    
- name: Release Build
  run: php bin/compiler.php src/*.php -O2
```

### 3. 调试检查清单

遇到问题时：
- [ ] 是否使用 `--debug-info` 编译？
- [ ] 是否设置了断点？
- [ ] 是否查看了调用堆栈？
- [ ] 是否检查了变量值？
- [ ] 是否使用了 AddressSanitizer？
- [ ] 是否查看了日志文件？

### 4. 符号文件管理

**Windows:**
```powershell
# 保留 PDB 文件
Copy-Item app.pdb symbols/

# 发布时剥离符号
# PDB 文件不需要分发给用户
```

**Linux:**
```bash
# 分离调试信息
objcopy --only-keep-debug app app.debug
strip app

# 使用时
gdb -s app.debug ./app
```

---

## 📚 相关资源

- [GDB 用户手册](https://sourceware.org/gdb/current/onlinedocs/gdb/)
- [LLDB 教程](https://lldb.llvm.org/use/tutorial.html)
- [Visual Studio 调试](https://docs.microsoft.com/visualstudio/debugger/)
- [MSVC 调试选项](https://docs.microsoft.com/cpp/build/reference/z7-zi-ld-debug-information-format)
- [GCC 调试选项](https://gcc.gnu.org/onlinedocs/gcc/Debugging-Options.html)

---

## 🔗 编译器命令参考

```powershell
# Windows - 基本调试
php bin/compiler.php app.php --debug-info

# Windows - 调试 + GUI
php bin/compiler.php app.php --debug-info --no-console

# Windows - 调试 + ASan
php bin/compiler.php app.php --debug-info --sanitize=address

# Linux/macOS - 基本调试
php bin/compiler.php app.php --debug-info

# Linux/macOS - 调试 + 多个 sanitizer
php bin/compiler.php app.php --debug-info --sanitize=address,undefined

# 自定义优化级别（不使用调试模式）
php bin/compiler.php app.php -O2

# 完全禁用优化（不生成调试信息）
php bin/compiler.php app.php -O0
```

---

## 💡 提示

1. **始终在开发时使用 `--debug-info`**
   - 更容易找到 bug
   - 更好的错误报告
   - 支持调试器

2. **发布前移除 `--debug-info`**
   - 更好的性能
   - 更小的文件
   - 更安全（不暴露符号）

3. **结合使用多种调试工具**
   - 调试器（GDB/LLDB/VS）
   - Sanitizer（AddressSanitizer 等）
   - 日志记录
   - Profiler

希望这个指南能帮助您有效使用调试模式！
