# Win32 Hello World 示例

这是一个使用 PHPX 编译器创建的最简单 Windows 图形界面程序示例。

## 项目结构

```
win32-hello/
├── hello-win.php          # 主程序（使用 C++ 辅助函数）
├── window.php             # 纯 PHP 版本（需要 Windows API 声明）
├── main.php               # 最简单的消息框示例
├── cpp-src/
│   └── winapi.cc          # C++ 实现的 Windows API 封装
└── project.yml            # 项目配置文件
```

## 编译和运行

### 方法 1: 使用项目配置（推荐）

```powershell
cd examples\win32-hello
php ..\..\bin\compiler.php project.yml
.\build\win32-hello.exe
```

### 方法 2: 直接编译单个文件

```powershell
# 编译最简单的版本
php bin\compiler.php examples\win32-hello\main.php

# 运行
.\main.exe
```

## 代码说明

### C++ 函数导出规范

要让 C++ 函数能被 PHP 调用，必须满足以下条件：

1. **函数名必须以 `php_` 为前缀**
   - 例如：`php_messagebox()` 在 PHP 中调用时为 `messagebox()`

2. **只能使用 PHPX 类型作为参数和返回值**
   - `Int`, `Bool`, `String`, `Double`, `Array`, `Object`, `Variant` 等
   - 不能使用原生 C/C++ 类型（如 `int`, `char*` 等）

3. **必须在 `.stub.php` 文件中声明**
   - stub 文件只包含函数签名（参数和返回值）
   - 不包含具体实现代码
   - 实现代码在对应的 `.cc` 或 `.cpp` 文件中

### 示例结构

**1. Stub 声明文件** (`cpp-src/winapi.stub.php`):
```php
<?php
// 只声明函数签名，不包含实现
function messagebox(int $hWnd, string $text, string $caption, int $uType): int {}
```

**2. C++ 实现文件** (`cpp-src/winapi.cc`):
```cpp
#include <phpx.h>
#include <windows.h>

using namespace php;

// 函数名必须以 php_ 为前缀
Int php_messagebox(Int hWnd, String text, String caption, Int uType) {
    return MessageBox((HWND)hWnd, text.c_str(), caption.c_str(), (UINT)uType);
}
```

**3. PHP 调用文件** (`hello-win.php`):
```php
<?php
// 直接调用，无需额外声明
function main() {
    $result = messagebox(0, "Hello!", "Title", 0);
}
```

### 最简单的版本 (main.php)

直接使用 Windows API（需要编译器支持原生函数声明）：

```php
#[NativeFunction]
function MessageBox(int $hWnd, string $lpText, string $lpCaption, int $uType): int {}

function main() {
    MessageBox(0, "Hello World!", "标题", 0);
}
```

## 扩展：创建完整窗口

要创建真正的 Windows 窗口（而不是消息框），需要：

1. **注册窗口类** (WNDCLASS)
2. **实现窗口过程函数** (WindowProc)
3. **创建消息循环** (GetMessage/TranslateMessage/DispatchMessage)

这些功能需要在 C++ 层实现，因为涉及到回调函数和复杂的 Windows 数据结构。

## 注意事项

- Windows API 函数需要通过 `#[NativeFunction]` 声明
- 复杂的 Windows API 建议在 C++ 层封装
- 编译时需要链接 Windows 系统库（user32.lib, gdi32.lib 等）
- 程序必须是 `bin` 模式才能创建图形界面

## 参考

- [Windows API 文档](https://docs.microsoft.com/en-us/windows/win32/api/)
- [PHPX 编译器文档](../../docs/README.md)
- [examples/prime](../prime) - 混合 PHP 和 C++ 的示例
