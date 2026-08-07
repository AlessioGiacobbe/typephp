# 构建 TypePHP WASI 程序

TypePHP 使用稳定的 WASI 0.2（Preview 2）和 Component Model。TypePHP 生成的 C++、PHPX 核心、精简的 PHP 8.5 NTS、GMP、MPFR 和 mpdecimal 会静态链接为单个 `.wasm` command component。WASI 0.1（Preview 1）不受支持。

## 环境要求

- WASI SDK 33 或更高版本（LLVM/Clang/LLD 22 或更高）
- PHP 8.4 或更高版本，用于运行 TypePHP 编译器
- Wasmtime 47 或更高版本，用于运行和测试产物
- Jco 1 或更高版本，用于 browser profile；component profile 不需要 Jco
- 与当前 TypePHP 版本绑定的 `wasm32-wasip2` 集成 SDK

WASI SDK 的 `bin` 目录和 Wasmtime 必须加入系统 `PATH`。编译器不会探测或使用 `/opt` 等约定安装目录，也不接受专用的工具目录配置。WASI 静态库和头文件统一安装到 PHPX 的 `wasm/wasm32-wasip2/`：

```bash
export PATH="<wasi-sdk-bin>:<wasmtime-bin>:$PATH"
```

TypePHP 使用现有的 PHPX 定位规则：优先读取 `PHPX_HOME`，其次读取 Composer 的 `swoole/phpx` 安装位置，最后使用 `vendor/swoole/phpx`。不新增 WASI 专用环境变量。

WASI 构建会检查 `wasm32-wasip2-clang`、`wasm32-wasip2-clang++`、`llvm-ar`、`llvm-ranlib`、`llvm-nm`、`wasm-component-ld` 和 `wasmtime`，并确认目标是 `wasm32-unknown-wasip2`。browser profile 另外检查 `jco`。所有工具只从 `PATH` 查找；npm script 会自动将项目本地的 `node_modules/.bin` 加入 `PATH`。

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

单文件输入默认生成当前目录下的 `hello.wasm` 和 `hello.browser/` Jco 模块。生成的 `.cc` 与 host 模式使用相同的 build 目录规则，默认位于 TypePHP 根目录的 `build/`；可以使用 `--build-dir <directory>` 覆盖。

项目可以直接使用 `project.yml`：

```yaml
name: wasm-hello
mode: bin
target-platform: wasm32-wasip2
wasm: true
build-dir: build
output: component/wasm-hello.wasm
sources:
  - src
wasm-browser-dir: generated
```

配置 `wasm: true` 后，直接执行 `php bin/tpc.php project.yml` 即可进入 WASI browser 构建，无需重复传入 `--wasm`。`build-dir`、`output` 和 `wasm-browser-dir` 都相对于项目文件解析。完整浏览器应用见 `examples/wasm-hello/`。

若项目永久只生成 Component，也可以直接配置 `wasm: component`。

命令行也可以显式选择产物：

- `--wasm` 或 `--wasm=browser`：生成 Component 和 Jco 浏览器模块，需要 `jco` 位于 `PATH`。
- `--wasm=component`：仅生成可由 Wasmtime 运行的 Component，不检测 Jco。

路径、sources 等详细配置继续放在 `project.yml`，不通过 `--wasm=` 传递。

PHP、PHPX、TypePHP runtime、GMP、MPFR 和 mpdecimal 由 SDK 发布阶段预编译为 WASI 静态库。应用构建只编译 TypePHP 为当前程序生成的 C++，然后链接这些 `.a`。`tpc --wasm` 不会下载源码、运行 `wit-bindgen`，也不会调用 PHP、PHPX 或高精度库的构建脚本。

PHP/WASI 当前静态内建 `date`、`pcre`、`hash`、`json`、`lexbor`、`random`、`Reflection`、`SPL`、`standard`、`uri`、`ctype`、`calendar`、`bcmath`、`filter` 和 `tokenizer` 扩展。

每个 C/C++ 翻译单元统一使用标准 Wasm C++ exceptions 和 WASI SJLJ；链接阶段将 ABI 警告视为错误，旧的 32 位 `zend_long` 缓存也会自动失效。

运行：

```bash
wasmtime hello.wasm
```

Chrome Demo：

```bash
cd examples/wasm-hello
npm run wasm
npm ci
npm run dev
```

浏览器端始终在专用 Worker 中执行 Component。默认使用内存文件系统；发送给 Worker 的启动消息设置 `persistent: true` 后，会在启动和退出时通过 OPFS 恢复、保存文件系统快照。程序执行期间仍使用同步内存文件系统，避免每次 PHP 文件访问跨越异步 JS 边界。

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
- Fiber 和 Generator 被禁用；编译器在发现 `yield` 时直接报致命错误。
- PHPX Facade API 在 `__wasi__` 下整体禁用。PHPX 核心类型和 `phpx_std` 仍可使用。
- 不支持动态扩展、网络 socket、进程、shell 和信号。静态可识别的调用会在编译期报致命错误。
- 保留 PHP stream 框架、本地文件能力以及由 WASI host 提供的时间和随机数能力。
- `.wasm` 是同一份 WASI 0.2 command component：Wasmtime 直接运行；Chrome 使用 Jco 生成的 ESM 和 `examples/wasm-hello/typephp-worker.mjs` 中的 Worker host。

PHPX Facade 只是为 PHP 可选扩展生成的便捷包装，并非 TypePHP ABI 的组成部分。WASI 下整体关闭它，可以避免把不存在的 curl、socket、Swoole、PDO 等 API 暴露为“可编译但链接失败”的接口。

## WASI SDK 目录

集成 SDK 使用唯一、完整的前缀，位于 PHPX 根目录的 `wasm/wasm32-wasip2/`：

```text
phpx/wasm/wasm32-wasip2/
├── include/php/             # PHP 安装头文件
├── include/phpx/            # PHPX 和 TypePHP runtime 头文件
├── include/gmp.h ...
├── lib/libphp.a
├── lib/libphpx.a
├── lib/libgmp.a
├── lib/libgmpxx.a
├── lib/libmpfr.a
├── lib/libmpdec.a
├── lib/libmpdec++.a
└── .typephp-wasi-sdk-abi
```

普通用户通过 TypePHP/PHPX 集成安装包获得该目录。TypePHP 开发者需要自行 clone 与当前版本绑定的 `php-8.5.9-wasm` 和 PHPX 源码，分别构建 PHP、PHPX、GMP、MPFR 与 mpdecimal，再按上述结构安装到 PHPX checkout。若 PHPX 不在 `vendor/swoole/phpx`，继续使用已有的 `PHPX_HOME` 指向该 checkout。

不提供单独覆盖 `libphp.a`、`libphpx.a` 或数值库的路径；所有库、头文件和 `.typephp-wasi-sdk-abi` 必须来自同一次兼容构建，避免混用不同的 `zend_long`、C++ exceptions、SJLJ 或 Component Model ABI。
