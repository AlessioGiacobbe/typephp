# 构建 TypePHP WASI 程序

TypePHP 已有一个可运行的 WASI Preview 1 原型。它将 TypePHP 生成的 C++、PHPX 核心、精简的 PHP 8.5 NTS、GMP、MPFR 和 mpdecimal 静态链接为单个 `.wasm` command 模块。

## 环境要求

- WASI SDK 33 或更高版本（LLVM/Clang/LLD 22 或更高）
- PHP 8.4 或更高版本，用于运行 TypePHP 编译器
- Autoconf、Automake、Libtool、Bison、re2c 和常规 C/C++ 构建工具
- Wasmtime 47 或更高版本，用于运行和测试产物

WASI SDK 的 `bin` 目录和 Wasmtime 必须加入系统 `PATH`。编译器不会探测或使用 `/opt` 等约定安装目录，也不接受专用的工具目录配置。可以继续通过环境变量覆盖缓存位置：

```bash
export PATH="<wasi-sdk-bin>:<wasmtime-bin>:$PATH"
export PHP_WASM_BUILD_DIR=/tmp/php-8.5.9-wasm-build
export TYPEPHP_WASM_DEPS_PREFIX=/tmp/typephp-wasm-numeric/prefix
export TYPEPHP_WASM_RUNTIME_PREFIX=/tmp/typephp-wasm-runtime
```

执行 `--wasm` 时会先检查 `clang`、`clang++`、`llvm-ar`、`llvm-ranlib`、`llvm-nm`、`wasm-ld` 和 `wasmtime` 是否都能从 `PATH` 找到，检查最低版本，并确认 `clang++` 的默认目标是 `wasm32-wasi`。检测失败时不会进入代码生成或编译阶段。

## 一条命令构建

源文件必须提供 `main(): void`：

```php
<?php
function main(): void
{
    echo "Hello from TypePHP/WASI\n";
}
```

执行：

```bash
php bin/tpc.php --wasm hello.php
```

输出文件默认为当前目录下的 `hello.wasm`。生成的 `.cc` 与 host 模式使用相同的 build 目录规则，默认位于 TypePHP 根目录的 `build/`；可以使用 `--build-dir <directory>` 覆盖。每个 `.o` 与对应 `.cc` 放在同一目录并在下次构建时直接覆盖，不建立 WASM 专用的深层对象目录。编译器用于传递本次源码列表的临时清单会在链接结束后自动清理。

PHP、PHPX、TypePHP runtime、GMP、MPFR 和 mpdecimal 会预编译并缓存为 WASI 静态库；正常的应用构建只编译 TypePHP 为当前程序生成的 C++，然后链接这些 `.a`。源码开发环境首次执行时会自动建立缺失的运行时缓存，发行包可直接携带预编译静态库。

每个 C/C++ 翻译单元统一使用标准 Wasm C++ exceptions 和 WASI SJLJ；链接阶段将 ABI 警告视为错误，旧的 32 位 `zend_long` 缓存也会自动失效。

运行：

```bash
wasmtime hello.wasm
```

## 高精度类型

WASI 产物包含 TypePHP 的三种语言级高精度类型：

- `BigInt`：GMP 6.3.0
- `BigFloat`：MPFR 4.2.2
- `Decimal`：mpdecimal 4.0.1

完整示例位于 [high-precision.php](../projects/php-8.5.9/wasm/examples/high-precision.php)。构建并运行：

```bash
php bin/tpc.php --wasm projects/php-8.5.9/wasm/examples/high-precision.php
wasmtime high-precision.wasm
```

预期输出：

```text
1111111101111111110111111111010
1000000000000000000000000000001
12348.14159265358979324
```

wasm32 使用 32 位指针，但 PHP 的 `zend_long` 保持 64 位，以维持 TypePHP 与 64 位 PHP 的整数语义。GMP 和 mpdecimal 使用 32 位 limb；这不改变任意精度语义，但大数吞吐量低于具有汇编优化的原生 64 位构建。

## 当前平台边界

- 仅支持 NTS、单线程。
- Fiber 和 TypePHP Generator 被禁用；编译器在发现 `yield` 时直接报致命错误。
- PHPX Facade API 在 `__wasi__` 下整体禁用。PHPX 核心类型和 `phpx_std` 仍可使用。
- 不支持动态扩展、网络 socket、进程控制和依赖操作系统服务的扩展。
- 保留 PHP stream 框架、本地文件能力以及由 WASI host 提供的时间和随机数能力。
- 当前产物是 WASI command module，适用于 Wasmtime 等 WASI 运行时，不能不经宿主适配直接放入浏览器运行。

PHPX Facade 只是为 PHP 可选扩展生成的便捷包装，并非 TypePHP ABI 的组成部分。WASI 下整体关闭它，可以避免把不存在的 curl、socket、Swoole、PDO 等 API 暴露为“可编译但链接失败”的接口。

## 内部构建层次

编译器内部会分别建立 PHP 8.5 NTS、GMP/MPFR/mpdecimal，以及 PHPX core/TypePHP runtime 的静态库缓存。内部构建脚本不是用户接口，不需要也不应由用户手动执行。发行包可直接附带目标平台对应的预编译 `.a` 文件。

用户始终通过 `php bin/tpc.php --wasm program.php` 构建最终程序，避免 PHP、PHPX 和生成代码使用不一致的 `zend_long`、异常或 SJLJ ABI。
