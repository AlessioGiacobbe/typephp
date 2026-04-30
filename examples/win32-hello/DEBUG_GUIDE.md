# Windows 程序调试指南

## 常见问题：Debug Assertion Failed

### 问题描述

运行编译后的程序时出现 "Debug Assertion Failed" 错误对话框。

### 原因分析

1. **CRT 库冲突**：混合使用了调试版和发布版的 C 运行时库
2. **内存访问错误**：访问了无效内存或空指针
3. **初始化失败**：PHP 或 PHPX 未正确初始化

---

## 🔧 解决方案

### 方案 1：添加 /NODEFAULTLIB 选项（已实现）

编译器现在会自动添加以下链接选项来排除冲突的 CRT 库：

```cpp
/NODEFAULTLIB:LIBCMT    // 排除静态多线程 CRT
/NODEFAULTLIB:LIBCMTD   // 排除静态多线程调试 CRT
/NODEFAULTLIB:MSVCRTD   // 排除动态多线程调试 CRT
```

这样可以确保只使用动态多线程 CRT（`/MD`），避免库冲突。

**重新编译：**
```powershell
php bin/compiler.php examples/win32-hello/project.yml --no-console
```

---

### 方案 2：使用 Visual Studio 调试器

#### 步骤 1：以调试模式编译

```powershell
# 启用调试信息
php bin/compiler.php examples/win32-hello/project.yml --debug-info --no-console
```

这会在编译时添加 `/Zi` 选项，生成完整的调试符号。

#### 步骤 2：在 Visual Studio 中打开可执行文件

1. 打开 Visual Studio 2022
2. 菜单：**文件** → **打开** → **项目/解决方案**
3. 选择 `win32_hello.exe`
4. 或者直接将 exe 文件拖入 Visual Studio

#### 步骤 3：设置断点

1. 在代码视图中找到您想调试的位置
2. 点击行号左侧的灰色区域，设置红色断点
3. 或者按 `F9` 在当前行设置断点

#### 步骤 4：启动调试

1. 按 `F5` 或点击 **调试** → **开始调试**
2. 程序会在断点处暂停
3. 可以查看变量值、调用堆栈等信息

#### 步骤 5：附加到正在运行的进程

如果程序已经启动但出现问题：

1. 在 Visual Studio 中：**调试** → **附加到进程**
2. 找到 `win32_hello.exe` 进程
3. 点击 **附加**
4. 程序会在下一个断点或异常处暂停

---

### 方案 3：使用 WinDbg 调试

WinDbg 是 Windows SDK 中的强大调试工具。

#### 安装 WinDbg

```powershell
# 通过 Microsoft Store 安装
winget install Microsoft.WinDbg
```

#### 使用方法

```powershell
# 启动 WinDbg 并加载程序
windbg .\win32_hello.exe

# 或者附加到正在运行的进程
windbg -p <PID>
```

#### 常用命令

```
g           # 继续执行 (Go)
k           # 显示调用堆栈 (Stack trace)
dv          # 显示局部变量
!analyze -v # 详细分析崩溃原因
bp <地址>   # 设置断点
```

---

### 方案 4：添加日志输出

由于 GUI 程序没有控制台，可以将调试信息写入日志文件：

```php
<?php

function debug_log(string $message): void
{
    $logFile = __DIR__ . '/debug.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(
        $logFile,
        "[$timestamp] $message\n",
        FILE_APPEND | LOCK_EX
    );
}

function main()
{
    debug_log("程序启动");
    
    try {
        date_default_timezone_set('Asia/Shanghai');
        debug_log("时区设置成功");
        
        debug_log("准备显示第一个消息框");
        messagebox(0, "测试消息", "调试", 0);
        debug_log("第一个消息框已关闭");
        
        debug_log("准备显示第二个消息框");
        messagebox(0, "程序即将退出", "再见", 0);
        debug_log("程序正常退出");
    } catch (\Exception $e) {
        debug_log("错误: " . $e->getMessage());
        debug_log("堆栈跟踪: " . $e->getTraceAsString());
    }
}
```

**查看日志：**
```powershell
Get-Content .\debug.log -Wait
```

---

### 方案 5：使用消息框调试

对于简单的调试，可以使用消息框显示变量值：

```php
<?php

function main()
{
    date_default_timezone_set('Asia/Shanghai');
    
    // 调试：显示当前时间
    $currentTime = date('Y-m-d H:i:s');
    messagebox(0, "当前时间: $currentTime", "调试信息", 0);
    
    // 调试：检查函数是否存在
    $funcExists = function_exists('messagebox') ? '存在' : '不存在';
    messagebox(0, "messagebox 函数: $funcExists", "调试信息", 0);
    
    // 主逻辑
    messagebox(0, "欢迎！", "Hello", 0);
}
```

---

## 🛡️ 预防措施

### 1. 确保正确的编译选项

```php
// 在 project.yml 或编译命令中
- 使用 /MD（动态 CRT）而不是 /MT（静态 CRT）
- 使用 /O2 优化（发布模式）或 /Od（调试模式）
- 添加 /EHsc 启用 C++ 异常处理
```

### 2. 检查依赖库

确保所有依赖的 DLL 都存在且版本匹配：

```powershell
# 检查程序依赖的 DLL
dumpbin /dependents win32_hello.exe

# 或使用 Dependency Walker
depends.exe win32_hello.exe
```

### 3. 验证 PHP 环境

```powershell
# 检查 PHP 版本
php -v

# 检查 PHP 扩展
php -m

# 确认使用的是正确的 PHP（8.4+）
where php
```

---

## 📊 调试检查清单

当遇到 "Debug Assertion Failed" 时，按以下步骤检查：

- [ ] 是否使用了正确的 PHP 版本（8.4+）？
- [ ] Visual Studio 环境是否正确设置？
- [ ] 编译时是否使用了 `/MD` 选项？
- [ ] 链接时是否添加了 `/NODEFAULTLIB` 选项？
- [ ] 所有依赖的 DLL 是否都存在？
- [ ] 程序是否有足够的权限运行？
- [ ] 是否尝试过以管理员身份运行？
- [ ] 是否查看了 Windows 事件查看器中的错误日志？

---

## 🔍 高级调试技巧

### 1. 使用 ProcMon 监控系统调用

[Process Monitor](https://docs.microsoft.com/en-us/sysinternals/downloads/procmon) 可以监控：
- 文件访问
- 注册表操作
- 进程/线程活动
- DLL 加载

**使用方法：**
1. 下载并运行 ProcMon
2. 设置过滤器：`Process Name is win32_hello.exe`
3. 运行程序
4. 观察是否有 `ACCESS DENIED` 或 `NAME NOT FOUND` 错误

### 2. 使用 Application Verifier

Windows 自带的 Application Verifier 可以检测：
- 内存泄漏
- 句柄泄漏
- 堆损坏

**启用方法：**
```powershell
# 以管理员身份运行
appverif -enable Heaps -for win32_hello.exe
```

### 3. 查看 Windows 事件日志

```powershell
# 查看应用程序事件日志
Get-EventLog -LogName Application -Source "Application Error" -Newest 10

# 或打开事件查看器
eventvwr.msc
```

---

## 💡 常见问题解答

### Q: 为什么只在调试模式下出错，发布模式正常？

A: 调试模式启用了额外的检查和断言，会捕获潜在问题。发布模式 optimizations 可能掩盖了这些问题。

### Q: 如何禁用 Debug Assertion 对话框？

A: 
```cpp
// 在 C++ 代码开头添加
#define _CRT_DISABLE_PERFCRIT_LOCKS
_CrtSetReportMode(_CRT_ASSERT, _CRTDBG_MODE_FILE);
_CrtSetReportFile(_CRT_ASSERT, _CRTDBG_FILE_STDERR);
```

或在 PHP 中设置环境变量：
```php
putenv('_NO_DEBUG_HEAP=1');
```

### Q: 程序闪退，看不到错误信息怎么办？

A: 
1. 使用日志记录（见方案 4）
2. 在 cmd 中运行：`win32_hello.exe > output.txt 2>&1`
3. 使用 ProcMon 监控
4. 检查 Windows 事件查看器

### Q: 如何在没有 Visual Studio 的情况下调试？

A: 
1. 使用 WinDbg（免费）
2. 添加详细的日志输出
3. 使用消息框显示调试信息
4. 查看 Windows 事件日志

---

## 📚 相关资源

- [Visual Studio 调试教程](https://docs.microsoft.com/visualstudio/debugger/)
- [WinDbg 文档](https://docs.microsoft.com/windows-hardware/drivers/debugger/)
- [CRT 库冲突解决方案](https://docs.microsoft.com/cpp/build/reference/nodefaultlib-ignore-library)
- [Windows 调试技术](https://docs.microsoft.com/windows/win32/debug/)

---

## 🎯 快速修复步骤

如果遇到 "Debug Assertion Failed"，按以下顺序尝试：

1. **重新编译**（使用最新的修复）
   ```powershell
   php bin/compiler.php examples/win32-hello/project.yml --no-console
   ```

2. **清理构建目录**
   ```powershell
   Remove-Item -Recurse -Force build/
   php bin/compiler.php examples/win32-hello/project.yml --no-console
   ```

3. **添加调试信息重新编译**
   ```powershell
   php bin/compiler.php examples/win32-hello/project.yml --debug-info --no-console
   ```

4. **使用 Visual Studio 调试**
   - 打开 exe 文件
   - 按 F5 启动调试
   - 查看输出窗口的错误信息

5. **添加日志输出**
   - 在关键位置添加 `debug_log()` 调用
   - 查看日志文件定位问题

希望这些方法能帮助您成功调试程序！
