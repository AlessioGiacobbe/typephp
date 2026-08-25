[English](README.md) | [简体中文](README-CN.md)

<div align="center">

# TypePHP

**A native AOT compiler for PHP**

Compile PHP source code into native machine code ahead of time — producing
standalone executables, PHP extensions, and static libraries — while keeping
the PHP syntax you already know.

</div>

---

## What is TypePHP?

TypePHP is an Ahead-Of-Time (AOT) compiler that translates PHP source code into
C++ and then into native machine code. Unlike a bytecode cache or a VM, it does
not interpret opcodes at runtime: it generates optimized native binaries that
run directly on the CPU.

It keeps familiar PHP syntax and adds compile-time type information, so the
compiler can emit fast, statically-typed C++ for your hot paths — while the
rest of your code continues to run on the battle-tested Zend engine.

## Features

- **True AOT compilation** — PHP is lowered to C++17, then to native machine
  code. No interpreter, no opcode cache, no JIT warm-up.
- **Three build modes** — build a standalone `bin` executable, a loadable PHP
  `ext` extension, or a `lib` static library from the same codebase.
- **Native type system** — `int`, `float`, and `bool` map directly to C++
  scalar types (`int64_t`, `double`, `bool`) for orders-of-magnitude speedups
  on numeric code.
- **High-precision numerics** — `bigInt` (GMP), `decimal` (libmpdec), and
  `bigFloat` (MPFR) with zero-overhead arithmetic.
- **Strongly-typed containers** — `std::array`, `std::vector`, `std::map`, and
  `std::ordered_map` with compile-time element types; up to **10×** faster than
  PHP arrays and on par with C++ `std::vector`.
- **Universal methods** — call methods directly on primitives
  (`$s->upper()`, `$arr->contains()`, `$big->mul(2)`) with zero runtime
  dispatch overhead.
- **Mixed C++ / PHP** — call C++ functions from PHP (and vice versa) for
  performance-critical kernels.
- **Compile-time functions & keywords** — `any()`, `refval()`, `objval()`,
  `expected()`, `unexpected()`, plus `toInt()`, `toString()`, `toArray()` and
  friends.
- **Compile-time safety** — `#[Immutable]` read-only contracts and `#[ArrayDef]`
  array-shape metadata, checked at compile time with zero runtime cost.
- **Modern PHP support** — PHP 8.4 property hooks, asymmetric visibility,
  PHP 8.5 `clone()`-with, and `(void)` discard expressions.
- **Cross-platform & WASM** — Linux, Windows, and macOS targets for x86-64 and
  ARM64, plus WASI 0.2 and browser (Jco) output.
- **Python bridge** — generate IDE helpers for Python modules and convert
  Python scripts to TypePHP.

## Why TypePHP?

| | TypePHP AOT | Opcode cache (OPcache) | JIT (PHP 8+) |
|---|---|---|---|
| Compilation target | Native machine code | Bytecode | Machine code (trace) |
| Startup / warm-up | None (already compiled) | Per-process warm-up | JIT warm-up |
| Type-driven optimization | Compile-time, full-program | None | Limited, trace-based |
| Standalone executable | Yes | No | No |
| Source code protection | Compiled to machine code | Bytecode (reversible) | Bytecode (reversible) |
| Deterministic performance | Yes | No | No |

**Strengths over plain PHP:**

- **Near-native performance.** Numeric and container-heavy hot paths compile
  down to the same machine code a C++ program would produce. See the
  [benchmark](#benchmark) below.
- **Source protection.** Your source is compiled away — shipped artifacts are
  native binaries, not readable PHP files.
- **Zero-dependency deployment.** Binary mode produces a single self-contained
  executable that runs without a PHP runtime.
- **Gradual typing that actually pays off.** Add `use native_types`, `std::`
  containers, and type declarations only where performance matters; the rest
  stays ordinary PHP.
- **Full PHP ecosystem interop.** Extension mode loads as a standard PHP
  extension into `php-fpm`, so existing frameworks and tooling keep working.

## Requirements

- **PHP 8.4 – 8.5** with the `embed` module (`libphp.so`)
- **GCC 9+** (or Clang) with **C++17**
- **CMake 3.24+**
- High-precision math libraries: **GMP**, **MPFR**, **libmpdec**

```shell
# Ubuntu/Debian
sudo apt install libgmp-dev libmpfr-dev libmpdec-dev

# RHEL/CentOS/Fedora
sudo dnf install gmp-devel mpfr-devel libmpdec-devel

# Arch Linux
sudo pacman -S gmp mpfr mpdecimal
```

> GMP powers `bigInt`, MPFR powers `bigFloat`, and libmpdec powers `decimal`.

The preview currently targets **Linux** as the primary development platform
(Ubuntu 22.04 recommended). Windows and macOS packaging is supported through
the same entry point.

## Installation

### Via Composer

```bash
composer require --dev swoole/typephp
```

Then compile your project:

```bash
vendor/bin/tpc.php project.yml
```

When working inside the TypePHP source repository, use the local entry point
instead:

```bash
bin/tpc.php project.yml
```

### Building `libphp.so`

`tpc` requires a PHP built with the `embed` SAPI. If `libphp.so` is missing on
Linux, `tpc.php` can interactively download the PHP source and build it for
you. See [Automatic libphp.so build](docs/LIBPHP_INSTALLER.md).

## Quick Start

Create `hello.php`:

```php
<?php

function main(): void
{
    echo "Hello World!\n";
    var_dump(PHP_VERSION);
    var_dump(php_uname());
}
```

Compile and run it:

```bash
bin/tpc.php hello.php
./hello
```

Output:

```
Hello World!
string(5) "8.4.x"
string(16) "Linux ..."
```

> Binary mode requires a global `main()` function. It may be declared with no
> parameters, or as `main(int $argc, array $argv)` to receive command-line
> arguments, and must return `void`.

## Compilation Modes

TypePHP supports three build modes, selected with `-m` / `--mode`:

| Mode | Flag | Output | Needs `main()` | Typical use |
|---|---|---|---|---|
| Binary | `-m bin` (default) | Executable | Yes | CLI tools, long-running services, standalone apps |
| Extension | `-m ext` | `.so` / `.dll` | No | Web apps on `php-fpm`, drop-in PHP extension |
| Library | `-m lib` | Static library | No | Embedding compiled code into other projects |

```bash
# Binary (default)
bin/tpc.php app.php -o myapp

# PHP extension
bin/tpc.php extension/ -m ext -o my_extension

# Static library
bin/tpc.php lib/ -m lib -o mylib
```

See [Compilation modes](docs/COMPILATION_MODES.md) for details.

## Examples

### 1. Native types — compile-time numeric speedup

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

With `use native_types`, `int` variables become C++ `int64_t` and arithmetic
compiles to plain CPU instructions instead of ZendVM calls.

### 2. High-precision numerics

```php
<?php
declare(strict_types=1);
use native_types;

function main(): void
{
    // 54-digit integer — automatically detected and stored as bigInt
    $a = std::bigInt("123456789012345678901234567890123456789012345678901234");
    $b = std::bigInt("987654321098765432109876543210987654321098765432109876");

    echo $a->add($b)->toString() . "\n";   // exact, no overflow

    // Exact decimal arithmetic — no binary floating-point error
    $c = std::decimal("0.1")->add(std::decimal("0.2"));
    echo $c->toString() . "\n";            // "0.3"

    // 256-bit floating point
    $pi = std::bigFloat("3.14159265358979323846264338327950288419716939937510");
    echo $pi->mul(2)->toString() . "\n";
}
```

See [High-precision types](docs/HIGH_PRECISION_TYPES.md) and
[Native types](docs/NATIVE_TYPES.md).

### 3. Strongly-typed containers

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

    // key-value map with fixed key/value types
    $map = std::ordered_map(Type::String, Type::Int);
    $map["a"] = 1;
    $map["b"] = 2;
}
```

See [Std containers](docs/STD_CONTAINERS.md).

### 4. Universal methods

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

Method calls on primitives are resolved at compile time into direct C/C++
function calls — no vtable lookup, no reflection, no runtime dispatch. See
[Universal methods](docs/UNIVERSAL_METHODS.md).

### 5. Mixed C++ / PHP

Write performance-critical kernels in C++ and call them from PHP:

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
// math.stub.php — declares the C++ function signature
function fast_sum(int $a, int $b): int;
```

```php
<?php
function main(): void
{
    echo fast_sum(3, 4) . "\n";  // 7
}
```

See [Mixed C++/PHP](docs/MIXED_CPP_PHP.md).

## Benchmark

A 10000×100000 element update loop, comparing PHP arrays against TypePHP's
`std::array` and native C++:

| Implementation | Time |
|---|---|
| PHP array (JIT) | 67.6 s |
| `std::array` (TypePHP AOT) | **6.4 s** |
| C++ `std::vector` | 6.2 s |

`std::array` is roughly **10× faster** than PHP arrays and performs
identically to hand-written C++. See the full benchmark in
[Std containers](docs/STD_CONTAINERS.md).

## Command Line

```bash
bin/tpc.php <file|dir|project.yml> [options] [-- program-args...]
```

Common usage:

```bash
# Compile a single file
bin/tpc.php app.php

# Optimize and run, passing args to the program after `--`
bin/tpc.php app.php -O3 -r -- --flag value

# Compile a project defined in project.yml
bin/tpc.php project.yml -O2 -j 8

# Build a PHP extension
bin/tpc.php extension/ -m ext -o my_extension

# Only generate C++ (skip compile & link)
bin/tpc.php app.php --dry --build-dir /tmp/typephp-build

# Compile to WASI 0.2
bin/tpc.php --wasm app.php

# Compile for the browser (requires jco)
bin/tpc.php --wasm=browser app.php
```

Key options:

| Option | Description |
|---|---|
| `-O <0-3>` | Optimization level (default `0`) |
| `-d`, `--debug` | Debug build with symbols and source tracking |
| `-o`, `--output <file>` | Output file name |
| `-m`, `--mode <bin\|lib\|ext>` | Build mode (default `bin`) |
| `-r`, `--run` | Run after a successful build |
| `-j`, `--job <num>` | Parallel compile jobs (default `4`) |
| `--build-dir <dir>` | Directory for generated C++ and intermediates |
| `--dry` | Generate C++ only, skip compile and link |
| `--php-version <8.4\|8.5>` | PHP syntax version to accept |
| `--cxx-std <ver>` | C++ standard (e.g. `c++17`, `c++20`) |
| `--march <arch>` | Target instruction set (e.g. `native`) |
| `--lto` | Enable link-time optimization |
| `--sanitize <type>` | Enable a sanitizer (e.g. `address`) |

Run `bin/tpc.php --help` for the authoritative, up-to-date list. See
[Compiler CLI](docs/COMPILER_CLI.md) for details, including Bash completion:

```bash
source <(./tpc --generate-completion=bash)
```

## Python bridge

TypePHP ships a Python tool submodule that shares the `tpc` entry point:

```shell
# Generate IDE helpers for Python modules
./tpc --gen-python-helper math
./tpc --gen-python-helper numpy --output-dir .ide-helper

# Convert a Python script to TypePHP
./tpc --convert-python-to-php script.py > script.php
```

See [Python tool submodule](docs/python/tools.md).

## Documentation

- [Quick Start](docs/QUICKSTART.md) — minimal compilation flow
- [Compilation modes](docs/COMPILATION_MODES.md) — `bin`, `ext`, `lib`
- [Compiler CLI](docs/COMPILER_CLI.md) — CLI arguments and project config
- [Incompatible PHP features](docs/INCOMPATIBLE_PHP_FEATURES.md) — current limits
- [Native types](docs/NATIVE_TYPES.md) — native scalar types
- [High-precision types](docs/HIGH_PRECISION_TYPES.md) — BigInt / Decimal / BigFloat
- [Std containers](docs/STD_CONTAINERS.md) — strongly-typed containers
- [Universal methods](docs/UNIVERSAL_METHODS.md) — zero-overhead methods
- [Compile-time functions](docs/COMPILE_TIME_FUNCTIONS.md) — `any()`, `refval()`, `objval()`, …
- [Mixed C++/PHP](docs/MIXED_CPP_PHP.md) — C++/PHP interop
- [`#[Immutable]`](docs/IMMUTABLE.md) — compile-time read-only contracts
- [WASI build](docs/WASI_BUILD.md) — WASI targets

## License

TypePHP is licensed under the [GNU General Public License v3.0](LICENSE).

## Community

- Repository: <https://github.com/swoole/typephp>
- Copyright © 2026 上海识沃网络科技有限公司 (Swoole)
