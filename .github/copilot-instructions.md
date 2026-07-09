# Copilot instructions for this repository

## Project overview

TypePHP is an ahead-of-time compiler that translates PHP source into C++, then compiles and links it into a native binary or a PHP extension. The primary entrypoint is `tpc`, which boots `src/compiler.php`; that drives `TypePhp\Translator` through a fixed pipeline:

1. `prepare()` scans files, parses ASTs, collects symbols, and topologically sorts PHP files by cross-file symbol usage.
2. `convert()` turns PHP ASTs into generated `.cc` files while passing through native source files (`.cpp`, `.c`, `.s`, `.m`, `.mm`).
3. `compile()` chooses the platform/compiler backend, generates support sources and headers, and compiles sources, using `pcntl` parallelism when available.
4. `build()` links object files into the final executable or extension.

`src/Php/CompilerBase.php` contains most PHP-to-C++ translation logic and mixes in many traits for syntax handling and optimizations. `src/Php/Preprocessor.php` owns dependency discovery and file ordering. Platform-specific behavior lives under `src/Php/Platform/`, compiler backends under `src/Php/Backend/`, and metadata/state objects under `src/Php/Entity/` and `src/Php/Context/`.

## Setup and build commands

The repo expects PHP 8.2+, GCC 9+ with C++17, CMake 3.24+, and a compiled `swoole/phpx` dependency. Install PHP dependencies with:

```bash
composer install
```

Build `phpx` before relying on compiler runs:

```bash
cd vendor/swoole/phpx
cmake .
make -j32
```

Compile a project, directory, single file, or `project.yml`:

```bash
./tpc <path-to-project-or-file>
./tpc <path> -O2
./tpc <path> --mode=ext -o <output_name>
```

## Test commands

Run the PHPUnit suite:

```bash
./vendor/bin/phpunit
```

Run a single PHPUnit file or a single test method:

```bash
./vendor/bin/phpunit phpunit/src/Platform/PlatformTest.php
./vendor/bin/phpunit --filter testWindowsBasic phpunit/src/Platform/PlatformTest.php
```

Run PHPT integration tests:

```bash
php run-tests.php tests/aot/
php run-tests.php tests/aot/arrays.phpt
```

For parser/runtime comparison without AOT compilation, there are docs using:

```bash
php run-tests.php --no-aot tests/aot/arrow-functions.phpt
```

## Formatting

The repo ships a PHP CS Fixer config in `.php-cs-fixer.dist.php`:

```bash
php vendor/bin/php-cs-fixer fix --config=.php-cs-fixer.dist.php <path>
```

Generated C++ is auto-formatted by the compiler itself when `clang-format` is available.

## Configuration and repository conventions

- `project.yml` is the project-level build config. Important keys include `name`, `build-mode`, `cxx-std`, `cxx-flags`, `ld-flags`, `sources`, `ignore`, and `resource`.
- Command-line options intentionally override YAML values. `Translator` parses YAML first, then applies CLI arguments last.
- YAML parsing accepts both hyphenated and underscored variants for several keys, but existing examples use hyphenated names such as `build-mode` and `cxx-std`.
- In `bin` mode, compiled programs must define `main()`. In `ext` mode they do not.
- File discovery is mixed-language by design: PHP is translated, while native sources are compiled directly if they appear in configured sources.
- Generated files are written under `build/`, with generated C++ paths mirroring the source tree and generated headers under `build/include/`.
- Platform/compiler selection is centralized: `PlatformFactory` detects the OS, and `CompilerFactory` picks the backend (`Gcc`, `Clang`, `Msvc`) with environment/config overrides.

## Test-specific conventions

- PHPUnit tests for compiler internals should use `CompilerTest::create(ROOT_PATH)`, which enables test mode instead of normal fatal exits.
- `phpunit/bootstrap.php` exposes a `BaseTest::exec()` helper that expects compilation failures to surface as `TypePhp\Exception\TestError`.
- PHPT end-to-end tests live in `tests/aot/`; existing guidance and examples generally put executable test logic inside a `main()` function.
