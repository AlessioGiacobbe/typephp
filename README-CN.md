[简体中文](README-CN.md) | [English](README.md)

<div align="center">

# TypePHP

**PHP 原生 AOT 编译器**

将 PHP 源码提前（AOT）编译为原生机器码，生成独立的可执行文件、PHP 扩展和静态库，
同时保留你熟悉的 PHP 语法。

</div>

---

## 什么是 TypePHP？

TypePHP 是一个 AOT（Ahead-Of-Time，提前编译）编译器，它把 PHP 源码翻译为 C++，
再编译为原生机器码。与字节码缓存或虚拟机不同，它不会在运行时解释 opcode，
而是直接生成在 CPU 上运行的原生二进制。

它保留熟悉的 PHP 语法，同时引入编译期类型信息，让编译器为你的性能热点生成快速、
静态类型的 C++ 代码，而其余代码仍运行在久经考验的 Zend 引擎上。

TypePHP **完全由 PHP 语言编写**，并且**完全自举**：`tpc` 编译器二进制就是
用 TypePHP 编译编译器自身的 PHP 源码得到的。整个自举链路是纯 PHP——编译器
本身没有任何 C 或 C++ 胶水代码。

## 特性

- **完全自举、纯 PHP 实现** —— TypePHP 编译器完全由 PHP 语言编写，并能自举：
  用 `tpc` 编译编译器自身的源码，即可生成原生二进制。
- **真正的 AOT 编译** —— PHP 先降级为 C++17，再编译为原生机器码。无解释器、
  无 opcode 缓存、无 JIT 预热。
- **三种构建模式** —— 同一份代码可编译为独立 `bin` 可执行文件、可加载的 PHP
  `ext` 扩展，或 `lib` 静态库。
- **原生类型系统** —— `int`、`float`、`bool` 直接映射为 C++ 标量类型
  （`int64_t`、`double`、`bool`），数值代码可获得数量级的性能提升。
- **高精度数值** —— `bigInt`（GMP）、`decimal`（libmpdec）、`bigFloat`（MPFR），
  零开销算术运算。
- **强类型容器** —— `std::array`、`std::vector`、`std::map`、`std::ordered_map`，
  元素类型在编译期确定；最高比 PHP 数组快 **10 倍**，性能与 C++ `std::vector` 相当。
- **通用方法（Universal Methods）** —— 在原生类型上直接调用方法
  （`$s->upper()`、`$arr->contains()`、`$big->mul(2)`），零运行时派发开销。
- **混合 C++ / PHP 编程** —— 在性能关键内核中直接调用 C++ 函数（反之亦然）。
- **编译期函数与关键词** —— `any()`、`refval()`、`objval()`、`expected()`、
  `unexpected()`，以及 `toInt()`、`toString()`、`toArray()` 等。
- **编译期安全检查** —— `#[Immutable]` 只读契约和 `#[ArrayDef]` 数组结构元数据，
  在编译期检查，零运行时开销。
- **现代 PHP 支持** —— PHP 8.4 property hooks、非对称可见性、PHP 8.5
  `clone()`-with 以及 `(void)` 丢弃表达式。
- **跨平台与 WASM** —— 面向 x86-64 和 ARM64 的 Linux、Windows、macOS 目标，
  以及 WASI 0.2 和浏览器（Jco）输出。
- **Python 桥接** —— 为 Python 模块生成 IDE helper，并将 Python 脚本转换为 TypePHP。

## 为什么选择 TypePHP？

| | TypePHP AOT | 字节码缓存（OPcache） | JIT（PHP 8+） |
|---|---|---|---|
| 编译目标 | 原生机器码 | 字节码 | 机器码（trace） |
| 启动 / 预热 | 无（已编译完成） | 每进程预热 | JIT 预热 |
| 类型驱动优化 | 编译期、全程序 | 无 | 有限，基于 trace |
| 独立可执行文件 | 支持 | 不支持 | 不支持 |
| 源码保护 | 编译为机器码 | 字节码（可还原） | 字节码（可还原） |
| 性能确定性 | 是 | 否 | 否 |

**相较原生 PHP 的优势：**

- **接近原生的性能。** 数值密集和容器密集的热点路径会编译为与 C++ 程序相同的机器码。
  见下方[基准测试](#基准测试)。
- **源码保护。** 源码被编译掉——交付物是原生二进制，而不是可读的 PHP 文件。
- **零依赖部署。** 二进制模式生成单个自包含可执行文件，无需 PHP 运行时即可运行。
- **渐进式类型，真正带来收益。** 只在性能关键处添加 `use native_types`、`std::`
  容器和类型声明，其余保持普通 PHP。
- **完整 PHP 生态互通。** 扩展模式以标准 PHP 扩展形式加载到 `php-fpm`，
  现有框架和工具链可继续使用。

## 前置要求

- **PHP 8.4 – 8.5**，需包含 `embed` 模块（`libphp.so`）
- **GCC 9+**（或 Clang），支持 **C++17**
- **CMake 3.24+**
- 高精度数学库：**GMP**、**MPFR**（libmpdec 已随 PHPX 内置）

```shell
# Ubuntu/Debian
sudo apt install libgmp-dev libmpfr-dev

# RHEL/CentOS/Fedora
sudo dnf install gmp-devel mpfr-devel

# Arch Linux
sudo pacman -S gmp mpfr
```

> GMP 用于 `bigInt`，MPFR 用于 `bigFloat`。`decimal` 底层是 libmpdec，
> 已随 PHPX 内置，无需单独安装。

预览版目前以 **Linux** 为主要开发平台（推荐 Ubuntu 22.04）。Windows 和 macOS
打包通过同一入口点支持。

## 安装

### 通过 Composer

```bash
composer require --dev swoole/typephp
```

然后编译你的项目：

```bash
vendor/bin/tpc.php project.yml
```

在 TypePHP 源码仓库中开发时，改用本地入口：

```bash
bin/tpc.php project.yml
```

### 构建 `libphp.so`

`tpc` 需要以 `embed` SAPI 构建的 PHP。如果 Linux 上缺少 `libphp.so`，
`tpc.php` 可以交互式下载 PHP 源码并自动构建。详见
[自动构建 libphp.so](docs/LIBPHP_INSTALLER.md)。

## 快速开始

创建 `hello.php`：

```php
<?php

function main(): void
{
    echo "Hello World!\n";
    var_dump(PHP_VERSION);
    var_dump(php_uname());
}
```

编译并运行：

```bash
bin/tpc.php hello.php
./hello
```

输出：

```
Hello World!
string(5) "8.4.x"
string(16) "Linux ..."
```

> 二进制模式需要全局 `main()` 函数。它可以声明为无参数，或
> `main(int $argc, array $argv)` 以接收命令行参数，且必须返回 `void`。

## 编译模式

TypePHP 支持三种构建模式，通过 `-m` / `--mode` 选择：

| 模式 | 参数 | 输出 | 需要 `main()` | 典型用途 |
|---|---|---|---|---|
| 二进制 | `-m bin`（默认） | 可执行文件 | 是 | CLI 工具、常驻服务、独立应用 |
| 扩展 | `-m ext` | `.so` / `.dll` | 否 | `php-fpm` 上的 Web 应用、即插即用 PHP 扩展 |
| 库 | `-m lib` | 静态库 | 否 | 将编译后的代码嵌入其他项目 |

```bash
# 二进制（默认）
bin/tpc.php app.php -o myapp

# PHP 扩展
bin/tpc.php extension/ -m ext -o my_extension

# 静态库
bin/tpc.php lib/ -m lib -o mylib
```

详见[编译模式](docs/COMPILATION_MODES.md)。

## 使用示例

### 1. 原生类型 —— 编译期数值加速

```php
<?php
use native_types;

function fib(int $n): int
{
    if ($n == 1 || $n == 2) {
        return 1;
    }
    return fib($n - 1) + fib($n - 2);
}

function main(int $argc, array $argv): void
{
    $n = (int)$argv[1];
    $begin = microtime(true);
    echo fib($n) . "\n";
    echo "Time: " . (microtime(true) - $begin) . "\n";
}
```

```bash
bin/tpc.php fib.php -O3 -o fib
./fib 30
```

使用 `use native_types` 后，`int` 变量变为 C++ `int64_t`，算术运算直接编译为
CPU 指令，而不是 ZendVM 调用。

### 2. 高精度数值

```php
<?php
declare(strict_types=1);
use native_types;

function main(): void
{
    // 54 位整数 —— 自动识别并存储为 bigInt
    $a = std::bigInt("123456789012345678901234567890123456789012345678901234");
    $b = std::bigInt("987654321098765432109876543210987654321098765432109876");

    echo $a->add($b)->toString() . "\n";   // 精确计算，不会溢出

    // 精确的十进制运算 —— 无二进制浮点误差
    $c = std::decimal("0.1")->add(std::decimal("0.2"));
    echo $c->toString() . "\n";            // "0.3"

    // 256 位浮点数
    $pi = std::bigFloat("3.14159265358979323846264338327950288419716939937510");
    echo $pi->mul(2)->toString() . "\n";
}
```

详见[高精度类型](docs/HIGH_PRECISION_TYPES.md)和[原生类型](docs/NATIVE_TYPES.md)。

### 3. 强类型容器

```php
<?php
use native_types;

function main(): void
{
    $vector = std::vector(Type::Int);

    $vector[] = 1;
    $vector[] = 2;
    $vector[] = 3;

    $sum = 0;
    foreach ($vector as $value) {
        $sum += $value;
    }

    echo $sum . "\n";       // 6
    echo $vector[1] . "\n"; // 2

    // 固定 key/value 类型的映射
    $map = std::ordered_map(Type::String, Type::Int);
    $map["a"] = 1;
    $map["b"] = 2;
}
```

详见 [Std 容器](docs/STD_CONTAINERS.md)。

### 4. 通用方法

```php
<?php

function main(): void
{
    $s = "hello world";
    echo $s->length() . "\n";       // strlen()
    echo $s->upper() . "\n";        // strtoupper()
    echo $s->substr(0, 5) . "\n";   // substr()

    $arr = [1, 3, 5, 7, 9];
    echo $arr->count() . "\n";      // count()
    var_dump($arr->contains(3));    // in_array()

    $big = std::bigInt("12345678901234567890");
    echo $big->mul(2)->toString() . "\n";
}
```

原生类型上的方法调用在编译期被解析为直接的 C/C++ 函数调用——没有虚表查找、
没有反射、没有运行时派发。详见[通用方法](docs/UNIVERSAL_METHODS.md)。

### 5. 混合 C++ / PHP

用 C++ 编写性能关键内核，并在 PHP 中调用：

```cpp
// math.cpp
#include <phpx.h>

using namespace php;

var php_fast_sum(Int a, Int b) {
    return a + b;
}
```

```php
<?php
// math.stub.php —— 声明 C++ 函数签名
function fast_sum(int $a, int $b): int;
```

```php
<?php
function main(): void
{
    echo fast_sum(3, 4) . "\n";  // 7
}
```

详见[混合 C++/PHP](docs/MIXED_CPP_PHP.md)。

## 基准测试

### PHP 语言基准（来自 php-src）

TypePHP 使用 `-O3` 运行 PHP 源码树自带的官方 `bench.php` 与
`micro_bench.php` 语言性能测试：

| 基准 | 解释执行 PHP | TypePHP AOT（`-O3`） | 加速比 |
|---|---|---|---|
| `bench.php`（总计） | 5.034 秒 | **0.603 秒** | 约 8× |
| `micro_bench.php`（总计） | 13.045 秒 | **2.021 秒** | 约 6.5× |

两项基准覆盖 PHP 语言核心性能——函数调用、对象属性访问、数组/哈希访问、
字符串处理、控制流等。完整逐项报告见 [`bench.txt`](bench.txt)。

### std::array 对比 PHP 数组

一个 10000×100000 的元素累加循环，对比 PHP 数组、TypePHP `std::array`
与原生 C++：

| 实现 | 耗时 |
|---|---|
| PHP 数组（JIT） | 67.6 秒 |
| `std::array`（TypePHP AOT） | **6.4 秒** |
| C++ `std::vector` | 6.2 秒 |

`std::array` 比 PHP 数组快约 **10 倍**，性能与手写 C++ 完全一致。
完整基准测试见 [Std 容器](docs/STD_CONTAINERS.md)。

## 命令行

```bash
bin/tpc.php <file|dir|project.yml> [options] [-- program-args...]
```

常用示例：

```bash
# 编译单个文件
bin/tpc.php app.php

# 优化并运行，`--` 后的参数传给生成的程序
bin/tpc.php app.php -O3 -r -- --flag value

# 编译 project.yml 定义的项目
bin/tpc.php project.yml -O2 -j 8

# 生成 PHP 扩展
bin/tpc.php extension/ -m ext -o my_extension

# 只生成 C++（跳过编译与链接）
bin/tpc.php app.php --dry --build-dir /tmp/typephp-build

# 编译为 WASI 0.2
bin/tpc.php --wasm app.php

# 编译为浏览器目标（需要 jco）
bin/tpc.php --wasm=browser app.php
```

主要选项：

| 选项 | 说明 |
|---|---|
| `-O <0-3>` | 优化级别（默认 `0`） |
| `-d`, `--debug` | 调试构建，带符号和源码跟踪 |
| `-o`, `--output <file>` | 输出文件名 |
| `-m`, `--mode <bin\|lib\|ext>` | 构建模式（默认 `bin`） |
| `-r`, `--run` | 构建成功后运行 |
| `-j`, `--job <num>` | 并行编译任务数（默认 `4`） |
| `--build-dir <dir>` | 生成 C++ 与中间产物的目录 |
| `--dry` | 只生成 C++，跳过编译与链接 |
| `--php-version <8.4\|8.5>` | 接受的 PHP 语法版本 |
| `--cxx-std <ver>` | C++ 标准（如 `c++17`、`c++20`） |
| `--march <arch>` | 目标指令集（如 `native`） |
| `--lto` | 启用链接时优化 |
| `--sanitize <type>` | 启用 sanitizer（如 `address`） |

运行 `bin/tpc.php --help` 查看权威的最新参数列表。详见
[编译器命令行](docs/COMPILER_CLI.md)，包括 Bash 补全：

```bash
source <(./tpc --generate-completion=bash)
```

## Python 桥接

TypePHP 内置一个 Python 工具子模块，复用 `tpc` 入口：

```shell
# 为 Python 模块生成 IDE helper
./tpc --gen-python-helper math
./tpc --gen-python-helper numpy --output-dir .ide-helper

# 将 Python 脚本转换为 TypePHP
./tpc --convert-python-to-php script.py > script.php
```

详见 [Python 工具子模块](docs/python/tools.md)。

## 文档

- [快速入门](docs/QUICKSTART.md) —— 最小编译流程
- [编译模式](docs/COMPILATION_MODES.md) —— `bin`、`ext`、`lib`
- [编译器命令行](docs/COMPILER_CLI.md) —— CLI 参数与项目配置
- [不兼容 PHP 特性清单](docs/INCOMPATIBLE_PHP_FEATURES.md) —— 当前限制
- [原生类型](docs/NATIVE_TYPES.md) —— 原生标量类型
- [高精度类型](docs/HIGH_PRECISION_TYPES.md) —— BigInt / Decimal / BigFloat
- [Std 容器](docs/STD_CONTAINERS.md) —— 强类型容器
- [通用方法](docs/UNIVERSAL_METHODS.md) —— 零开销方法
- [编译期函数](docs/COMPILE_TIME_FUNCTIONS.md) —— `any()`、`refval()`、`objval()` 等
- [混合 C++/PHP](docs/MIXED_CPP_PHP.md) —— C++/PHP 互操作
- [`#[Immutable]`](docs/IMMUTABLE.md) —— 编译期只读契约
- [WASI 构建](docs/WASI_BUILD.md) —— WASI 目标

## 授权协议

TypePHP 采用 [GNU General Public License v3.0](LICENSE) 授权。

## 社区

- 代码仓库：<https://github.com/swoole/typephp>
- 版权所有 © 2026 上海识沃网络科技有限公司（Swoole）
