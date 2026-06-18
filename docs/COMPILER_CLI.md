# AOT 编译器命令行工具使用指南

## 📋 概述

PHP AOT 编译器是一个强大的命令行工具，可以将 PHP 源代码编译为原生可执行文件或 PHP 扩展。它支持多种编译选项、优化级别和构建模式。

---

## 🚀 快速开始

### 基本用法

```bash
# 编译单个 PHP 文件
./bin/compiler.php examples/hello.php

# 编译目录
./bin/compiler.php examples/myapp/

# 带优化编译
./bin/compiler.php examples/bench.php -O2

# 生成扩展
./bin/compiler.php my_extension/ -m ext -o myext
```

---

## 📖 命令格式

```
./bin/compiler.php <file/dir> [options]
```

### 参数说明

| 参数 | 说明 | 必需 |
|------|------|------|
| `<file>` | 要编译的 PHP 文件或目录 | ✅ 是 |

---

## ⚙️ 命令行选项详解

### 1. `-O <level>` - 优化级别

**别名**: `--optimize`  
**默认值**: `0`  
**取值范围**: `0-3`

控制 GCC 编译器的优化级别，影响生成代码的性能和大小。

| 级别 | 说明 | 适用场景 |
|------|------|----------|
| `-O0` | 无优化，调试模式 | 开发调试 |
| `-O1` | 基础优化 | 一般用途 |
| `-O2` | 标准优化（推荐） | 生产环境 |
| `-O3` | 激进优化 | 性能关键应用 |

**示例**:
```bash
# 无优化编译
./bin/compiler.php app.php -O0

# 标准优化
./bin/compiler.php app.php -O2

# 最大优化
./bin/compiler.php app.php -O3
```

---

### 2. `-m <mode>` / `--mode <mode>` - 编译模式

**默认值**: `bin`  
**可选值**: `bin`, `ext`

指定编译输出类型：可执行文件或 PHP 扩展。

#### 🔹 bin 模式（二进制）

生成独立的可执行文件，无需 PHP 环境即可运行。

**特点**:
- ✅ 需要 `main()` 函数作为入口
- ✅ 包含完整的运行时环境
- ✅ 可直接在命令行执行
- ❌ 不能作为 PHP 扩展加载

**示例**:
```bash
./bin/compiler.php myapp/ -m bin -o myapp
./myapp  # 直接运行
```

#### 🔸 ext 模式（扩展）

生成 PHP 扩展（.so/.dll），需要在 PHP 环境中运行。

**特点**:
- ✅ 不需要 `main()` 函数
- ✅ 可以作为模块加载到 PHP
- ✅ 支持与现有 PHP 代码集成
- ❌ 需要 PHP 环境

**示例**:
```bash
./bin/compiler.php myext/ -m ext -o myext
# 在 php.ini 中添加
extension=myext
```

---

### 3. `-o <file>` / `--output <file>` - 输出文件名

**别名**: `--output`  
**默认值**: 输入文件的基本名

指定生成的可执行文件或扩展的名称。

**命名规则**:
- ✅ 只能包含字母、数字和下划线
- ❌ 不能包含连字符（`-`）或星号（`*`）
- ❌ 不能是 C++ 保留关键字
- ❌ 不能与现有目录同名

**示例**:
```bash
# 自定义输出名称
./bin/compiler.php app.php -o my_application

# 带连字符的名称会自动转换
./bin/compiler.php my-app.php -o my_app  # ✅ 正确
./bin/compiler.php my-app.php -o my-app  # ❌ 错误，会转换为 my_app
```

---

### 4. `-j <num>` / `--job <num>` - 并行编译任务数

**默认值**: `4`

控制编译过程中并行处理的任务数量，影响编译速度。

**建议配置**:
- **单核 CPU**: `-j 1`
- **双核 CPU**: `-j 2`
- **四核 CPU**: `-j 4` (默认)
- **八核及以上**: `-j 8` 或更高

**示例**:
```bash
# 单线程编译（适合调试）
./bin/compiler.php large_project/ -j 1

# 多线程编译（适合生产）
./bin/compiler.php large_project/ -j 8
```

---

### 5. `-v` / `--verbose` - 详细输出

**别名**: `--verbose`  
**类型**: 开关（无参数）

启用详细输出模式，显示编译过程的详细信息。

**输出内容**:
- ✅ 每个文件的处理状态
- ✅ 跳过不支持语法的通知
- ✅ 编译进度信息
- ✅ 生成的中间文件路径

**示例**:
```bash
./bin/compiler.php app.php -v

# 输出示例：
# prepare: /path/to/app.php
# generate stub file: /path/to/app.php
# convert: /path/to/app.php
# format: /path/to/build/app.cpp
# Starting parallel compilation with 4 jobs for 5 files
# Successfully compiled 5 files
```

---

### 6. `-f` / `--force` - 强制编译

**别名**: `--force`  
**类型**: 开关

即使缓存存在也强制重新编译。

**使用场景**:
- 修改了底层 C++ 代码
- 怀疑缓存有问题
- 需要完全重新编译

**示例**:
```bash
# 强制重新编译
./bin/compiler.php app.php -f

# 结合优化使用
./bin/compiler.php app.php -O2 -f
```

---

### 7. `-p` / `--profile` - 性能分析

**别名**: `--profile`  
**类型**: 开关

启用性能分析功能，生成可用于性能分析的可执行文件。

**输出内容**:
- ✅ 函数执行时间统计
- ✅ 内存使用情况
- ✅ 调用次数统计

**示例**:
```bash
# 编译带性能分析的版本
./bin/compiler.php benchmark.php -p -O2

# 运行后会生成性能报告
./benchmark
cat benchmark.prof
```

---

### 8. `--no-literal-strings` - 禁用字符串优化

**类型**: 开关

禁用字面量字符串优化，所有字符串将在运行时动态创建。

**默认行为**:
- ✅ 字符串常量会被提取到全局数组
- ✅ 减少重复字符串的内存占用
- ✅ 提高字符串比较性能

**禁用后的影响**:
- ❌ 增加内存使用
- ❌ 降低字符串操作性能
- ✅ 可能减少编译时间

**示例**:
```bash
# 禁用字符串优化
./bin/compiler.php app.php --no-literal-strings
```

---

### 9. `--debug-line` - 启用调试行

**默认值**: `0`

在生成的 C++ 代码中包含源文件行号信息，用于调试。

**示例**:
```bash
# 启用调试行信息
./bin/compiler.php app.php --debug-line 1
```

---

### 10. `--debug` - 启用调试模式

**类型**: 开关

启用详细的调试信息输出。

**示例**:
```bash
# 启用调试信息
./bin/compiler.php app.php --debug
```

---

### 11. `-h` / `--help` - 显示帮助

**类型**: 开关

显示帮助信息和所有可用选项。

**示例**:
```bash
./bin/compiler.php -h
```

**输出**:
```
PHP AOT Compiler v1.0.0

USAGE:
    ./bin/compiler.php <file/dir> [options]

ARGUMENTS:
    <file>    Input PHP file/directory to compile

OPTIONS:
    -O <level>           Optimization level (0-3, default: 0)
    -p, --profile        Enable performance profiling
    -o, --output <file>  Output binary name (default: input basename)
    -v, --verbose        Verbose output
    -h, --help           Show this help message
    -f, --force          Force compile even if cache exists
    -m, --mode <mode>    Compilation mode, -m bin(binary) or -m ext(extension), default: bin
    -j, --job <num>      Number of parallel compilation jobs (default: 4)
    --no-literal-strings Disable literal strings optimization
    -I, --include-path   Add an additional C++ include directory (repeatable)
    -D, --define <macro> Define a preprocessor macro (repeatable, e.g. -D FOO=bar)
    --dry                Dry run: only generate C++ code, skip compilation and linking
    --lto                Enable Link Time Optimization (-flto)
    --format             Enable clang-format code formatting (disabled by default)
    --cxx-std <ver>      C++ standard version (c++17, c++20, etc., default: c++17)
    --march <arch>       Target CPU instruction set (e.g. native, x86-64-v3, armv8-a)
    -l, --link-lib <lib> Link against a library (repeatable, e.g. -lcurl)
    -L, --link-path <dir> Add a library search path (repeatable, e.g. -L/usr/local/lib)
    --build-dir <dir>    Specify build directory for generated C++ code

EXAMPLES:
    ./bin/compiler.php examples/hello.php
    ./bin/compiler.php examples/bench.php -O2
    ./bin/compiler.php examples/bench.php -O2 
    ./bin/compiler.php examples/extension -O2 -o myapp -m ext
    ./bin/compiler.php examples/app.php -O3 -o myapp -v
```

---

## 🎯 典型使用场景

### 场景一：开发环境调试

```bash
# 无优化，启用详细输出，单线程
./bin/compiler.php src/app.php -O0 -v -j 1
```

### 场景二：生产环境部署

```bash
# 标准优化，多线程编译
./bin/compiler.php src/app.php -O2 -j 8
```

### 场景三：性能关键应用

```bash
# 最大优化，启用性能分析
./bin/compiler.php benchmark.php -O3 -p
```

### 场景四：构建 PHP 扩展

```bash
# 生成扩展模块
./bin/compiler.php extension_src/ -m ext -o myext -O2
```

### 场景五：大型项目

```bash
# 使用配置文件，多目录编译
./bin/compiler.php project.yml -O2 -j 16 -v
```

### 场景六：使用外部 C++ 库

```bash
# 添加自定义头文件路径和预处理器宏
./bin/compiler.php app.php -I /opt/mylib/include -I ../shared/include -D MY_DEBUG=1 -O2
```

---

### 12. `-I <dir>` / `--include-path <dir>` - 添加头文件搜索路径

**别名**: `--include-path`  
**类型**: 可重复参数

添加额外的 C++ 头文件（`.h` / `.hpp`）搜索目录。编译器的 include 路径由两部分组成：
1. 系统默认路径（PHP 头文件、PHPX 头文件等）
2. 用户通过 `-I` 指定的自定义路径

**适用场景**:
- ✅ 引用外部 C/C++ 库的头文件
- ✅ 项目有自定义的 C++ 扩展代码
- ✅ 多个项目共享的头文件目录

**可重复使用**: 可以多次指定 `-I` 添加多个目录：
```bash
./bin/compiler.php app.php \
    -I /opt/openssl/include \
    -I /opt/mylib/include \
    -I ../shared/headers
```

**与 C++ 编译器等价**: `-I <dir>` 直接传递给 GCC/Clang/MSVC 的 `-I` 选项。

---

### 13. `-D <macro>` / `--define <macro>` - 定义预处理器宏

**别名**: `--define`  
**类型**: 可重复参数

定义 C++ 预处理器宏，等价于在 C++ 代码中使用 `#define`。使用 `name=value` 格式。

**适用场景**:
- ✅ 条件编译（`#ifdef MY_FEATURE` / `#ifndef MY_FEATURE`）
- ✅ 功能开关（`-D ENABLE_LOGGING=1`）
- ✅ 调试标志（`-D DEBUG_LEVEL=3`）
- ✅ 版本号定义（`-D APP_VERSION=\"2.0\"`）

**格式说明**:
| 格式 | 等价 C++ 代码 | 说明 |
|------|--------------|------|
| `-D FOO` | `#define FOO` | 无值宏（值为空） |
| `-D FOO=1` | `#define FOO 1` | 整数值宏 |
| `-D FOO=bar` | `#define FOO bar` | 字符串值宏 |
| `-D FOO=\"bar\"` | `#define FOO "bar"` | 引号字符串宏 |

**可重复使用**: 可以多次指定 `-D` 定义多个宏：
```bash
./bin/compiler.php app.php \
    -D MY_DEBUG=1 \
    -D LOG_LEVEL=3 \
    -D APP_NAME=\\"MyApp\"
```

**与 C++ 编译器等价**:
| 编译器 | 产生的编译标志 |
|--------|-------------|
| GCC / Clang | `-D<macro>` |
| MSVC | `/D<macro>` |

**示例: 条件编译控制功能开关**:
```bash
# 启用调试日志
./bin/compiler.php app.php -D ENABLE_LOGGING=1 -O2

# 生产环境（关闭调试）
./bin/compiler.php app.php -O2
```

---

### 14. `--lto` - 链接时优化

**类型**: 开关（无参数）

启用链接时优化（Link Time Optimization, LTO），允许编译器在链接阶段跨编译单元进行优化，可显著提升运行时性能和减小二进制体积。

**编译器适配**:
| 编译器 | 编译阶段标志 | 链接阶段标志 |
|--------|-----------|-----------|
| GCC | `-flto` | `-flto` |
| Clang | `-flto` | `-flto` |
| MSVC | `/GL` | `/LTCG` |

**适用场景**:
- ✅ 生产环境部署（配合 `-O2` 或 `-O3`）
- ✅ 对性能有极致要求的应用
- ✅ 需要减小二进制体积的场景
- ⚠️ 会增加链接时间

**示例**:
```bash
# 启用 LTO 的生产环境编译
./bin/compiler.php app.php -O2 --lto

# 与自定义 include 和 define 组合使用
./bin/compiler.php app.php -O3 --lto -I /opt/lib/include -D NDEBUG=1
```

---

### 15. `--format` - 代码格式化

**类型**: 开关（无参数）  
**默认**: 关闭

启用 `clang-format` 对生成的 C++ 代码进行自动格式化。由于格式化会增加编译时间，默认关闭。需要系统中安装了 `clang-format` 才能生效。

**适用场景**:
- ✅ 需要审查生成的 C++ 代码
- ✅ 团队开发需要统一的代码风格
- ⚠️ 会增加编译时间

**示例**:
```bash
# 编译时启用代码格式化
./bin/compiler.php app.php --format

# 结合优化使用
./bin/compiler.php app.php -O2 --format
```

如果系统未安装 `clang-format`，使用 `--format` 时会显示警告并跳过格式化。

---

### 16. `-l <lib>` / `--link-lib <lib>` - 链接库

**别名**: `--link-lib`  
**类型**: 可重复参数

指定要链接的库，等价于 GCC/Clang 的 `-l<lib>` 选项。实际产生的标志为 `-l<lib>`。

**适用场景**:
- ✅ 链接第三方 C/C++ 库（如 `-lcurl`、`-lssl`）
- ✅ 链接自定义编译的静态库/动态库
- ✅ 多库依赖的项目

**可重复使用**: 可以多次指定 `-l` 链接多个库：
```bash
./bin/compiler.php app.php \
    -lcurl \
    -lssl \
    -lcrypto

# 等价的长格式
./bin/compiler.php app.php --link-lib curl --link-lib ssl --link-lib crypto
```

**与 GCC/Clang 等价**: `-l<lib>` 直接传递给链接器的 `-l` 选项，链接 `lib<lib>.so` 或 `lib<lib>.a`。

---

### 17. `-L <dir>` / `--link-path <dir>` - 库搜索路径

**别名**: `--link-path`  
**类型**: 可重复参数

添加库文件搜索路径，等价于 GCC/Clang 的 `-L<dir>` 选项。实际产生的标志为 `-L<dir>`。

**适用场景**:
- ✅ 链接非标准路径下的库文件
- ✅ 使用自定义编译的本地库
- ✅ 链接项目内部的私有库

**可重复使用**: 可以多次指定 `-L` 添加多个搜索路径：
```bash
./bin/compiler.php app.php \
    -L/usr/local/lib \
    -L/opt/custom/lib \
    -lmycustom

# 等价的长格式
./bin/compiler.php app.php --link-path /usr/local/lib --link-path /opt/custom/lib --link-lib mycustom
```

**与 GCC/Clang 等价**: `-L<dir>` 直接传递给链接器的 `-L` 选项。

---

### 18. `--march <arch>` - 目标 CPU 指令集

**类型**: 单值参数

指定编译器生成针对特定 CPU 架构优化的代码。等价于 GCC/Clang 的 `-march=<arch>` 选项。

**常用值**:
| 值 | 说明 |
|---|---|
| `native` | 自动检测并优化当前 CPU 支持的指令集 |
| `x86-64-v3` | x86-64 微架构级别 3 (AVX, AVX2, BMI1, BMI2, F16C, FMA, LZCNT, MOVBE, XSAVE) |
| `x86-64-v4` | x86-64 微架构级别 4 (AVX512F, AVX512BW, AVX512CD, AVX512DQ, AVX512VL) |
| `armv8-a` | ARMv8-A 基础架构 |
| `armv8.1-a` | ARMv8.1-A |
| `armv8.2-a` | ARMv8.2-A |
| `armv9-a` | ARMv9-A |

> **⚠️ 注意**: `--march` 仅适用于 GCC/Clang 编译器。MSVC 不支持此选项，请使用 `--cxx-flags /arch:AVX2` 等方式。

**示例**:
```bash
# 优化当前机器运行的 CPU
./bin/compiler.php app.php --march=native -O2

# 特定目标架构
./bin/compiler.php app.php --march=x86-64-v3 -O2

# ARM 平台
./bin/compiler.php app.php --march=armv8-a -O2
```

**与 GCC/Clang 等价**: `--march=<arch>` 直接传递给编译器的 `-march=<arch>` 选项。

---

## 🔧 编译器选择

### 默认编译器

编译器会根据操作系统自动选择合适的 C++ 编译器：

| 平台 | 默认编译器 | 说明 |
|------|----------|------|
| **macOS** | `clang++` | 系统自带，性能优秀 |
| **Linux** | `g++` | GNU 编译器集合 |
| **Windows** | `cl` (MSVC) | Microsoft Visual C++ |

### 通过环境变量切换编译器

你可以通过设置环境变量来覆盖默认的编译器选择。

#### 方法一：PHPX_CC（推荐）

```bash
# macOS 使用 GCC（如果已安装）
export PHPX_CC=g++
php bin/compiler.php examples/hello.php

# Linux 使用 Clang
export PHPX_CC=clang++
php bin/compiler.php examples/hello.php

# Windows 使用 Clang
set PHPX_CC=clang++
php bin\compiler.php examples\hello.php
```

#### 方法二：CXX（标准环境变量）

```bash
# 使用标准的 CXX 环境变量
export CXX=clang++
php bin/compiler.php examples/hello.php
```

**优先级**：`PHPX_CC` > `CXX` > 平台默认

### 通过配置文件指定编译器

在项目 YAML 配置文件中，可以使用 `cpp-compiler` 选项指定编译器：

```yaml
name: myapp
type: bin
cpp-compiler: clang++  # 或 g++, cl
sources:
  - src/*.php
```

支持的编译器名称：
- `clang++` / `clang` - LLVM Clang 编译器
- `g++` / `gcc` - GNU GCC 编译器
- `cl` / `msvc` - Microsoft Visual C++（仅 Windows）

### 检查当前使用的编译器

编译时会显示使用的编译器信息：

```bash
$ php bin/compiler.php examples/hello.php
Initialized new architecture: macOS + Clang
prepare: examples/hello.php
prepare completed: 1 source files in total
...
```

---

## 📁 支持的输入类型

### 1. 单个 PHP 文件

```bash
./bin/compiler.php hello.php
```

### 2. 目录

```bash
./bin/compiler.php src/
```

编译器会自动扫描目录下所有的 `.php` 文件。

### 3. YAML 配置文件

```bash
./bin/compiler.php project.yml
```

**project.yml 示例**:
```yaml
name: myapp
type: bin
sources:
  - src/*.php
  - lib/**/*.php
  - main.php
```

---

## 🔧 编译过程详解

### 阶段一：预处理 (Prepare)

```
prepare: /path/to/file.php
```

- ✅ 解析 PHP 文件
- ✅ 检查语法错误
- ✅ 检测不支持的语法
- ✅ 生成抽象语法树 (AST)

### 阶段二：生成存根文件 (Generate Stub)

```
generate stub file: /path/to/file.php
```

- ✅ 生成 `.stub.php` 文件
- ✅ 提取函数声明
- ✅ 生成参数信息头文件

### 阶段三：转换为 C++ (Convert)

```
convert: /path/to/file.php
```

- ✅ 将 PHP AST 转换为 C++ 代码
- ✅ 生成对应的 `.cpp` 文件
- ✅ 处理类型映射

### 阶段四：格式化 (Format)（需 `--format` 开启）

```
format: /path/to/build/file.cpp
cd /path && clang-format -i /path/to/build/file.cpp
```

- 🔘 需 `--format` 参数显式开启
- ✅ 使用 clang-format 格式化 C++ 代码
- ✅ 确保代码风格一致

### 阶段五：并行编译 (Parallel Compilation)

```
Starting parallel compilation with 4 jobs for 5 files
Successfully compiled 5 files
```

- ✅ 使用多个进程并行编译 C++ 文件
- ✅ 生成目标文件 (.o)

### 阶段六：链接 (Link)

```
g++ ... -o app ...
```

- ✅ 链接所有目标文件
- ✅ 链接 PHPX 库和 PHP 库
- ✅ 生成最终可执行文件

---

## ⚡ 性能优化技巧

### 1. 选择合适的优化级别

```bash
# 开发阶段：快速编译
-O0

# 测试阶段：平衡编译时间和性能
-O1

# 生产阶段：最大化性能
-O2 或 -O3
```

### 2. 调整并行任务数

根据 CPU 核心数调整：
```bash
# 查看 CPU 核心数
nproc

# 设置为 CPU 核心数的 1.5 倍
-j $(($(nproc) * 3 / 2))
```

### 3. 使用缓存

编译器会自动缓存已编译的文件，下次编译时会跳过未更改的文件。

```bash
# 第一次编译（慢）
./bin/compiler.php app.php

# 第二次编译（快，使用缓存）
./bin/compiler.php app.php

# 强制重新编译
./bin/compiler.php app.php -f
```

---

## 🐛 故障排除

### 问题一：编译失败 "No valid source file found"

**原因**: 没有找到有效的 PHP 文件

**解决方法**:
```bash
# 检查文件路径是否正确
ls -la your_file.php

# 使用绝对路径
./bin/compiler.php /absolute/path/to/file.php
```

### 问题二：类名冲突

**原因**: 输出名称与现有类名冲突

**解决方法**:
```bash
# 使用不同的输出名称
./bin/compiler.php app.php -o myapp_binary
```

### 问题三：内存不足

**原因**: 并行任务数过多导致内存耗尽

**解决方法**:
```bash
# 减少并行任务数
./bin/compiler.php large_project.php -j 2
```

### 问题四：不支持的语法

**原因**: 使用了 AOT 编译器不支持的 PHP 语法

**输出示例**:
```
unsupported syntax: Dynamic property creation is not supported
skip: /path/to/file.php
```

**解决方法**:
1. 查看 [UNSUPPORTED_SYNTAX.md](docs/UNSUPPORTED_SYNTAX.md)
2. 修改代码使用支持的语法
3. 或将代码封装到函数中

---

## 📊 编译选项组合示例

### 示例组合

```bash
# 快速开发构建
./bin/compiler.php app.php -O0 -j 1

# 标准生产构建
./bin/compiler.php app.php -O2 -j 8 -v

# 高性能构建
./bin/compiler.php app.php -O3 -j 16 -p

# 外部库集成构建
./bin/compiler.php app.php -I /opt/mylib/include -D ENABLE_FEATURE=1 -O2

# 调试构建
./bin/compiler.php app.php -O0 -v --debug

# 扩展构建
./bin/compiler.php ext/ -m ext -o myext -O2 -v
```

---

## 📝 最佳实践

### 1. 开发环境

```bash
# 使用脚本自动化
#!/bin/bash
./bin/compiler.php src/app.php -O0 -v -j 1
```

### 2. CI/CD 流水线

```bash
# 持续集成
./bin/compiler.php tests/ -O2 -j $(nproc) -v
```

### 3. 生产部署

```bash
# 生产环境
./bin/compiler.php release/app.php \
    -O2 \
    -j $(nproc) \
    -o app \
    -v \
    --force
```

### 4. 性能测试

```bash
# 基准测试
./bin/compiler.php benchmark.php -O3 -p -o benchmark_optimized
```

---

## 🎓 高级主题

### 1. 自定义编译流程

可以通过修改 `src/config/compiler_options.php` 添加自定义选项。

### 2. 扩展编译器

实现自定义的 Preprocessor 或 Translator 来扩展编译器功能。

### 3. 性能调优

分析编译过程中的瓶颈：
```bash
time ./bin/compiler.php large_project.php -v
```

---

## 📚 相关资源

- **快速入门**: [QUICKSTART.md](QUICKSTART.md)
- **编译模式**: [COMPILATION_MODES.md](COMPILATION_MODES.md)
- **原生类型**: [NATIVE_TYPES.md](NATIVE_TYPES.md)
- **混合编程**: [MIXED_CPP_PHP.md](MIXED_CPP_PHP.md)
- **语法限制**: [UNSUPPORTED_SYNTAX.md](UNSUPPORTED_SYNTAX.md)

---

**最后更新**: 2024 年 3 月 19 日  
**适用版本**: PHP AOT Compiler v1.0.0
