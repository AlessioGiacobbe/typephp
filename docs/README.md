# TypePHP 编译器内部文档

本目录包含编译器实现、兼容性、构建模式和专项设计文档。用户侧使用手册位于独立的 `aot/docs` 仓库；这里的研究报告和重构计划可能描述历史状态，当前行为应以代码、测试和兼容性清单为准。

## 当前权威文档

- [AOT 与 PHP 不兼容特性清单](INCOMPATIBLE_PHP_FEATURES.md)：当前限制的简明清单。
- [不兼容性分类](PHP_INCOMPATIBILITY_CLASSIFICATION.md)：区分 Hard Limit、Intentional Rule、Pending 和 Partial。
- [编译器命令行](COMPILER_CLI.md)：当前 CLI 参数和项目配置。
- [编译模式](COMPILATION_MODES.md)：binary、extension、library 模式。
- [快速入门](QUICKSTART.md)：最小编译流程。
- [编译期函数](COMPILE_TIME_FUNCTIONS.md)：`any()`、`refval()`、`objval()`、`expected()`、`unexpected()` 和关键词方法。
- [原生类型](NATIVE_TYPES.md)、[高精度类型](HIGH_PRECISION_TYPES.md)、[Std 容器](STD_CONTAINERS.md)。
- [通用与扩展方法](UNIVERSAL_METHODS.md)、[Generator](YIELD_GENERATOR.md)。
- [类继承](CLASS_INHERITANCE.md)、[混合 C++/PHP](MIXED_CPP_PHP.md)。

## 架构与维护

- [后端中立 IR](BACKEND_NEUTRAL_IR.md)
- [TypePHP WASM 技术方案与实施计划](TYPEPHP_WASM_IMPLEMENTATION_PLAN.md)
- [构建 TypePHP WASI 程序](WASI_BUILD.md)
- [核心重构计划](REFACTORING_PLAN.md)
- [作用域管理设计](SCOPE_MANAGEMENT.md)：`CallableScope`、`UserCodeScopeGuard` 与 `FakeScopeGuard` 的职责和使用边界。
- [构建速度研究](AOT_BUILD_SPEED_RESEARCH.md)
- [优化优先级](aot-optimization-priority.md)
- [高精度类型原地运算优化方案](BIG_NUMBER_INPLACE_OPTIMIZATION_PLAN.md)
- [GMP 差异](GMP_GAP.md)

## 研究与历史资料

`hhvm-review.md`、`kphp-review.md`、`peachpie-review.md`、`phpstan-design-analysis.md`、`php-src-optimizer-analysis.md` 以及专利草案用于记录调研时点的比较和设计背景，不作为当前功能清单。

## 维护规则

1. 当前兼容性变化同时更新 `INCOMPATIBLE_PHP_FEATURES.md` 和分类文档。
2. 所有语法和语义限制统一链接到当前兼容性清单，避免维护重复清单。
3. 功能是否支持应以 PHPT/PHPUnit 回归测试为依据。
4. 历史研究文档保留原始比较结论，并在需要时注明调研日期，不应悄然改写为当前状态。
