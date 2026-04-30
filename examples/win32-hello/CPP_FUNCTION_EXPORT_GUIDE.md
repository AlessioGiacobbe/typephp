# PHPX 编译器 - C++ 函数导出规范

## 概述

在 PHPX 编译器中，C++ 函数可以被导出为 PHP 函数，供 PHP 代码调用。这需要遵循特定的规范和约定。

## 三大必要条件

### 1. 函数名必须以 `php_` 为前缀

```cpp
// ✅ 正确：以 php_ 为前缀
Int php_messagebox(Int hWnd, String text, String caption, Int uType) {
    // 实现代码
}

// ❌ 错误：缺少 php_ 前缀
Int messagebox(Int hWnd, String text, String caption, Int uType) {
    // 这不会被导出到 PHP
}
```

**命名规则：**
- C++ 函数名：`php_messagebox()`
- PHP 调用名：`messagebox()`（自动去掉 `php_` 前缀）

### 2. 只能使用 PHPX 类型作为参数和返回值

**支持的 PHPX 类型：**

| PHPX 类型 | PHP 对应类型 | 说明 |
|-----------|-------------|------|
| `Int` | `int` | 整数 |
| `Bool` | `bool` | 布尔值 |
| `Double` | `float` | 浮点数 |
| `String` | `string` | 字符串 |
| `Array` | `array` | 数组 |
| `Object` | `object` | 对象 |
| `Variant` | `mixed` | 混合类型 |
| `void` | 无返回值 | 仅用于返回值 |

```cpp
// ✅ 正确：使用 PHPX 类型
Int php_add(Int a, Int b) {
    return a + b;
}

String php_greet(String name) {
    return "Hello, " + name + "!";
}

// ❌ 错误：使用原生 C/C++ 类型
int php_add(int a, int b) {  // 错误！
    return a + b;
}

char* php_greet(char* name) {  // 错误！
    return name;
}
```

### 3. 必须在 `.stub.php` 文件中声明

**Stub 文件的作用：**
- 只包含函数签名（参数和返回值类型）
- 不包含具体实现代码
- 让编译器知道有哪些 C++ 函数可供 PHP 调用

**Stub 文件示例** (`winapi.stub.php`)：

```php
<?php

/**
 * Windows API 封装函数的声明文件（stub）
 * 这些函数在 C++ 中实现，PHP 层只负责声明
 */

// 显示消息框
function messagebox(int $hWnd, string $text, string $caption, int $uType): int {}

// 获取模块句柄
function get_module_handle(string $moduleName): int {}

// 创建窗口
function create_window(
    string $className, 
    string $windowName, 
    int $style, 
    int $x, 
    int $y, 
    int $width, 
    int $height
): int {}

// 显示窗口
function show_window(int $hWnd, int $cmdShow): bool {}

// 退出消息循环
function post_quit_message(int $exitCode): void {}
```

**注意事项：**
- Stub 文件必须是 `.stub.php` 扩展名
- 函数体为空（`{}`），不包含任何代码
- 参数类型和返回值类型必须与 C++ 实现一致
- 函数名不需要 `php_` 前缀（编译器会自动添加）

## 完整示例

### 项目结构

```
my-extension/
├── main.php              # PHP 主程序
├── cpp-src/
│   ├── mylib.stub.php    # Stub 声明文件
│   └── mylib.cc          # C++ 实现文件
└── project.yml           # 项目配置
```

### 1. Stub 声明文件 (`cpp-src/mylib.stub.php`)

```php
<?php

// 计算两个整数的和
function add(int $a, int $b): int {}

// 拼接字符串
function concat(string $str1, string $str2): string {}

// 判断是否为偶数
function is_even(int $number): bool {}
```

### 2. C++ 实现文件 (`cpp-src/mylib.cc`)

```cpp
#include <phpx.h>

using namespace php;

// 注意：函数名必须以 php_ 为前缀
Int php_add(Int a, Int b) {
    return a + b;
}

String php_concat(String str1, String str2) {
    return str1 + str2;
}

Bool php_is_even(Int number) {
    return (number % 2 == 0);
}
```

### 3. PHP 调用文件 (`main.php`)

```php
<?php

function main() {
    // 直接调用 C++ 函数，无需额外声明
    $sum = add(10, 20);
    echo "10 + 20 = $sum\n";  // 输出: 10 + 20 = 30
    
    $greeting = concat("Hello, ", "World!");
    echo "$greeting\n";  // 输出: Hello, World!
    
    if (is_even(42)) {
        echo "42 是偶数\n";
    }
}
```

### 4. 项目配置 (`project.yml`)

```yaml
name: my-extension
version: 0.0.1
sources:
  - main.php
  - ./cpp-src
```

## 编译流程

```
1. 编译器读取 .stub.php 文件
   ↓
2. 生成对应的函数声明头文件
   ↓
3. 编译 C++ 实现文件（.cc/.cpp）
   ↓
4. 链接所有目标文件
   ↓
5. 生成可执行文件或扩展
```

## 常见错误

### 错误 1：函数名没有 `php_` 前缀

```cpp
// ❌ 错误
Int add(Int a, Int b) {
    return a + b;
}

// ✅ 正确
Int php_add(Int a, Int b) {
    return a + b;
}
```

**症状：** PHP 调用时提示函数未定义

### 错误 2：使用了原生 C/C++ 类型

```cpp
// ❌ 错误
int php_add(int a, int b) {
    return a + b;
}

// ✅ 正确
Int php_add(Int a, Int b) {
    return a + b;
}
```

**症状：** 编译错误或类型不匹配

### 错误 3：没有在 stub 文件中声明

```cpp
// C++ 中有实现
Int php_my_function(Int x) {
    return x * 2;
}
```

但没有在 `.stub.php` 中声明。

**症状：** PHP 调用时提示函数未定义

### 错误 4：Stub 和 C++ 实现类型不一致

```php
// stub.php
function add(int $a, int $b): string {}  // 返回 string
```

```cpp
// mylib.cc
Int php_add(Int a, Int b) {  // 返回 Int，不一致！
    return a + b;
}
```

**症状：** 编译错误或运行时类型错误

## 最佳实践

1. **组织文件结构**
   - 将相关的 stub 和 C++ 文件放在同一目录
   - 使用有意义的文件名（如 `winapi.stub.php` 和 `winapi.cc`）

2. **类型安全**
   - 始终使用正确的 PHPX 类型
   - 避免类型转换，除非必要

3. **注释清晰**
   - 在 stub 文件中添加函数说明
   - 在 C++ 实现中添加详细注释

4. **错误处理**
   - 在 C++ 函数中进行参数验证
   - 使用 `zend_throw_error()` 抛出异常

5. **命名规范**
   - 使用小写字母和下划线分隔单词
   - 保持函数名简洁明了

## 参考资源

- [PHPX 官方文档](https://github.com/swoole/phpx)
- [examples/prime](../prime) - 完整的 C++ 混合编程示例
- [examples/win32-hello](../win32-hello) - Windows API 封装示例
