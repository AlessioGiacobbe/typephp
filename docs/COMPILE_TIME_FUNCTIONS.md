# AOT 编译期函数与关键词方法

本文档记录 AOT 编译器专有的编译期函数、关键词方法和相关构造入口。它们不是标准 PHP 语法的一部分，普通 PHP 运行时只能依赖 `src/polyfills.php` 提供的兼容占位。

## 核心编译期函数

当前核心全局编译期函数共 3 个。

| 名称 | 参数 | 作用 | 当前主要处理位置 |
| --- | --- | --- | --- |
| `any($value)` | 1 个 | 将表达式降级为 `mixed/any`，阻止继续按静态 native/object 类型处理。 | 赋值右值路径中特判。 |
| `refval($target)` | 1 个 | 显式把变量、数组元素或对象属性作为引用传给动态调用或无法静态识别引用参数的调用。 | 参数解析、动态调用、SSA/优化器引用逃逸分析。 |
| `objval($value, ClassName::class 或 'ClassName')` | 2 个 | 告诉编译器 `$value` 是指定类对象，并生成 `php::toObject(..., target_ce)` 运行时兜底检查。 | 函数调用解析、对象类型推导。 |

约束：

- `refval()` 只接受变量、数组元素或对象属性。
- `objval()` 第二个参数必须是编译期可解析的类名字符串或 `ClassName::class`。
- `any()` 语义上应是任意表达式位置可用的编译期标记；当前实现仍有路径差异，后续应统一到表达式解析入口，而不是只在部分赋值路径中处理。

## 关键词方法

当前内置关键词方法共 12 个。

| 名称 | 等价行为 | 说明 |
| --- | --- | --- |
| `toAny()` | `any($receiver)` | 返回接收者本身，但类型降级为 `mixed/any`。 |
| `toRef()` | `refval($receiver)` | 返回接收者引用；参数限制与 `refval()` 一致。 |
| `toObject()` | `php::toObject($receiver)` | 可带目标类参数，执行对象转换/检查。 |
| `toInt()` | `php::toInt($receiver)` | 转为 native int 表达式。 |
| `toFloat()` | `php::toFloat($receiver)` | 转为 native float 表达式。 |
| `toString()` | `php::toString($receiver)` | 转为字符串表达式。 |
| `toBool()` | `php::toBool($receiver)` | 转为 bool 表达式。 |
| `toArray()` | `php::toArray($receiver)` | 转为数组表达式。 |
| `toStream()` | `php::toStream($receiver)` | 转为 stream 表达式。 |
| `toBigInt()` | `php::BigInt::newInstance($receiver)` | 构造 BigInt。 |
| `toBigFloat()` | `php::BigFloat::newInstance($receiver)` | 构造 BigFloat。 |
| `toDecimal()` | `php::Decimal::newInstance($receiver)` | 构造 Decimal。 |

约束：

- `toAny()`、`toRef()` 不接受参数。
- `toRef()` 只适用于可取引用的接收者。
- 关键词方法优先于普通方法和 universal method 分派。

## `std::` 编译期构造入口

当前 `std::` 编译期构造入口共 10 个。

| 名称 | 作用 | 主要限制 |
| --- | --- | --- |
| `std::int($value)` | 显式创建 native int 表达式。 | 需要 1 个值参数。 |
| `std::float($value)` | 显式创建 native float 表达式。 | 需要 1 个值参数。 |
| `std::bool($value)` | 显式创建 native bool 表达式。 | 需要 1 个值参数。 |
| `std::bigInt($value)` | 构造 BigInt。 | 不允许从 float 变量隐式构造。 |
| `std::decimal($value)` | 构造 Decimal。 | float 变量需改用字符串或整型；float 字面量会按原始字面量处理。 |
| `std::bigFloat($value)` | 构造 BigFloat。 | 需要 1 个值参数。 |
| `std::array($type, $size[, ...$sizes])` | 构造固定大小 std array。 | 只能在变量首次赋值的顶层作用域使用。 |
| `std::vector($type[, $size])` | 构造 std vector。 | 只能在变量首次赋值的顶层作用域使用。 |
| `std::map($keyType, $valueType)` | 构造 std map。 | 只能在变量首次赋值的顶层作用域使用。 |
| `std::ordered_map($keyType, $valueType)` | 构造 std ordered map。 | 只能在变量首次赋值的顶层作用域使用。 |

## Std 容器转换关键词方法

当前 Std 容器转换关键词方法共 4 个。

| 名称 | 作用 | 主要限制 |
| --- | --- | --- |
| `toStdArray(...)` | 将变量包装为 std array。 | 只能在变量首次赋值的顶层作用域使用。 |
| `toStdVector(...)` | 将变量包装为 std vector。 | 只能在变量首次赋值的顶层作用域使用。 |
| `toStdMap(...)` | 将变量包装为 std map。 | 只能在变量首次赋值的顶层作用域使用。 |
| `toStdOrderedMap(...)` | 将变量包装为 std ordered map。 | 只能在变量首次赋值的顶层作用域使用。 |

## 不计入本文清单的机制

- `$array->any()` 是 universal method，映射到 PHP `array_any()`，不是 `any()` 编译期函数。
- `native_types::type_*`、`complex_types::type_*` 是编译期类型描述常量，不是函数。
- keyword extension method 是用户自定义扩展方法机制，不属于固定内置编译期函数清单。

## 当前实现风险

编译期函数应当在任意合法表达式位置可用，并且在所有路径上保持一致语义。当前代码中仍存在处理入口分散的问题：

- `any()` 主要在赋值右值路径中被特殊识别，表达式参数、二元运算、返回值等位置可能走普通函数调用或依赖 polyfill。
- `refval()` / `toRef()` 在参数解析和动态调用路径中特判较多，后续应统一为一个“引用包装表达式”解析入口。
- `objval()` 当前通过函数调用解析和类型推导路径识别，整体较集中。

后续重构目标：

- 建立统一的 `CompileTimeFunctionResolver` 或等价模块。
- 在 `parseExpr()` / `detectTypeOfExpr()` / `detectClassOfExpr()` / 参数解析路径中复用同一份编译期函数元信息。
- 保证 `any()`、`refval()`、`objval()` 在任意表达式位置行为一致。
