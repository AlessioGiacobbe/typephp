# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Swoole-Compiler is an AOT (Ahead-of-Time) compiler that translates PHP source code to C++, then compiles it with GCC/Clang/MSVC into native binaries. It supports Linux (primary), macOS, and Windows.

**Prerequisites**: PHP 8.2+, GCC 9+ (C++17), CMake 3.24+. The `swoole/phpx` extension must be compiled (see README.md).

## AOT Language Design Principles

The AOT compiler should not blindly mirror every PHP language behavior. Most PHP syntax and semantics should remain compatible with ZendPHP, but some legal PHP constructs are historical baggage or language-design mistakes that conflict with static compilation, clear semantics, or robust generated C++ code.

When reviewing or changing compiler behavior:

- Prefer PHP compatibility for common, well-defined syntax that does not weaken the AOT static model.
- Reject PHP historical baggage when the syntax is ambiguous, surprising, or only preserved for legacy compatibility.
- Diagnose such cases as early as possible during preprocessing/static compilation, instead of deferring to runtime TypeCheck or ZendVM errors.
- Provide precise errors that include the relevant function/method name, parameter/property name, and type information where applicable.
- Compare with other statically compiled languages such as C/C++, Java, C#, Go, Rust, Kotlin, and TypeScript before deciding whether AOT should preserve or reject a PHP behavior.

Example: `function test($a = 1, $b, $c) {}` is legal in PHP, but the default value for `$a` is effectively ignored and all parameters become required. This is a PHP historical compatibility artifact. AOT should reject it during preprocessing instead of preserving the behavior.

## Build & Test Commands

```bash
# Install PHP dependencies
composer install

# Compile a PHP project to a native binary
php bin/compiler.php <path-to-project-or-file>

# Run all PHPUnit tests
./vendor/bin/phpunit

# Run a single PHPUnit test class
./vendor/bin/phpunit phpunit/src/AstNodeTypeTest.php

# Run PHPT integration tests (all)
php run-tests.php tests/aot/

# Run a single PHPT test
php run-tests.php tests/aot/arrays.phpt
```

## Architecture

### Translation Pipeline

The compiler follows a 4-stage pipeline, orchestrated by `src/Php/Translator.php` (the main entry point):

1. **prepare()** — Scan PHP files, collect symbol declarations and dependencies, topological-sort for compilation order
2. **convert()** — Parse PHP AST via `nikic/php-parser`, translate each node to C++ source code
3. **compile()** — Invoke the platform C++ compiler (GCC/Clang/MSVC) on generated `.cc` files
4. **build()** — Link object files into a native binary executable

### Class Hierarchy

```
src/Core/Translator (abstract base — indent/output/mode helpers)
 └─ src/Php/CompilerBase (core PHP→C++ translation logic)
     ├─ uses traits: AstNodeType, FuncCallOptimizer, ClosureGenerator,
     │    PlaceHolderGenerator, PropertyPromotion, MagicMethodDetector
     └─ src/Php/Preprocessor (scanning, symbol tables, dependency sort, YAML config)
         └─ src/Php/Translator (full pipeline: prepare→convert→compile→build)
             └─ src/Php/CompilerTest (test-only subclass, used by PHPUnit tests)
```

### Key Components

| Directory | Purpose |
|-----------|---------|
| `src/Php/Entity/` | Data classes: `ClassDef`, `FunctionDef`, `MethodDef`, `PropertyDef`, `ConstantDef`, `InterfaceDef` |
| `src/Php/Generator/` | Codegen helpers: `ClosureGenerator`, `PlaceHolderGenerator`, `PropertyPromotion`, `Utils` |
| `src/Php/Backend/` | Compiler abstraction: `CompilerBackend` (abstract) → `Gcc`, `Clang`, `Msvc`. Factory pattern via `CompilerFactory` |
| `src/Php/Platform/` | OS abstraction: `PlatformBase` → `Linux`, `Macos`, `Windows`. Factory via `PlatformFactory` |
| `src/Php/Context/` | `ScopeContext` and `FunctionContext` for variable scoping and type tracking |
| `src/Php/Exception/` | `SyntaxError`, `Unsupported`, `DynamicCall`, `PlaceHolder`, `Skip`, `Redo`, `TestError` |
| `src/Php/Parser/` | Special-purpose parsers like `StdContainerParser` (C++ std container foreach support) |
| `src/Php/Reflection.php` | Static helpers wrapping PHP reflection (internal class/function detection) |
| `src/Php/Symbol.php` | Maps PHP operations to `phpx` C++ API symbol names |
| `src/Php/FileScanner.php` | Recursive file discovery with extension filtering (supports `.php`, `.cpp`, `.c`, `.s`, `.m`, `.mm`) |
| `src/Php/ArgInfo.php` | Generates C function argument info structures for internal function registration |
| `src/Php/Extractor.php` | Extracts interfaces from PHP classes |
| `src/Php/Visitor.php` | Base `NodeVisitorAbstract` extension (skeleton for custom AST visitors) |
| `src/Python/Translator.php` | Python-to-C++ translator (separate from the PHP pipeline) |

### Configuration

- `project.yml` — per-project build config (name, build-mode, C++ standard, compiler flags, sources, resources/icon)
- Command-line arguments and YAML config are merged in `Preprocessor`, with CLI taking highest priority

### Generated Output

Generated `.cc` and `.o` files land in `build/` directory. The compiled binary is named from `project.yml`'s `name` field (default: `app`).

### Test Infrastructure

- **PHPUnit tests** (`phpunit/src/`) — unit/integration tests for compiler internals. Bootstrap at `phpunit/bootstrap.php` defines a `BaseTest` class with an `exec()` helper that runs the compiler and expects a `TestError` exception containing a given string
- **PHPT tests** (`tests/aot/`) — end-to-end tests using the standard PHPT format (`run-tests.php`). Each `.phpt` contains PHP source and expected output sections

When writing new compiler tests, use `CompilerTest::create(ROOT_PATH)` (in `src/Php/CompilerTest.php`) which sets `forTest = true` to enable test-specific behavior without writing files to disk.
