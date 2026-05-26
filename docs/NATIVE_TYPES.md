# AOT 编译器类型系统说明

## ⚠️ 重要提示

**AOT 编译器支持 6 种原生/高精度类型**:

### 基础原生类型
1. ✅ `std::int` - 原生整数类型 (zend_long, 8 字节)
2. ✅ `std::float` - 原生浮点类型 (double, 8 字节)
3. ✅ `std::bool` - 原生布尔类型 (bool, 1 字节)

### 高精度数值类型
4. ✅ `std::bigInt` - 任意精度整数 (基于 GMP `mpz_class`)
5. ✅ `std::decimal` - 任意精度十进制数 (基于 libmpdec, ~50 位有效数字)
6. ✅ `std::bigFloat` - 任意精度浮点数 (基于 MPFR)

---

## 🎯 objval 编译期函数

### 使用场景

当从数组、函数返回值等来源获取对象时，变量会丢失类型上下文信息。此时需要使用 `objval()` 显式声明对象的类。

### 基本语法

```php
<?php
// objval 接收两个参数：
// 1. 对象变量（必须是 PHP variable 表达式）
// 2. 类名（必须是字面量字符串）

$obj = objval($array['object'], 'ClassName');
```

### 典型场景

#### 场景一：从数组提取对象

```php
<?php
$data = [
    'user' => new User(),
    'product' => new Product(),
];

// ❌ 错误：类型丢失
$user = $data['user'];  // AOT 无法推断类型

// ✅ 正确：使用 objval 声明类型
$user = objval($data['user'], 'User');
$product = objval($data['product'], 'Product');
```

#### 场景二：函数返回对象

```php
<?php
function get_object() {
    return new stdClass();
}

// ❌ 类型丢失
$obj = get_object();

// ✅ 使用 objval 声明
$obj = objval(get_object(), 'stdClass');
```

#### 场景三：工厂模式

```php
<?php
class Factory {
    public function create($type) {
        switch ($type) {
            case 'user':
                return new User();
            case 'product':
                return new Product();
            default:
                throw new InvalidArgumentException("Invalid type");
        }
    }
}

$factory = new Factory();

// ✅ 明确指定返回的对象类型
$user = objval($factory->create('user'), 'User');
$product = objval($factory->create('product'), 'Product');
```

### 注意事项

⚠️ **必须使用字面量字符串**:

```php
<?php
// ✅ 正确：字面量类名
$obj = objval($value, 'MyClass');

// ❌ 错误：变量类名（编译期无法分析）
$className = 'MyClass';
$obj = objval($value, $className);  // 编译错误

// ❌ 错误：常量类名（编译期可能无法解析）
const CLASS_NAME = 'MyClass';
$obj = objval($value, CLASS_NAME);  // 可能失败
```

⚠️ **第一个参数必须是 variable 表达式**:

```php
<?php
// ✅ 正确：variable 表达式
$obj = objval($array['key'], 'MyClass');
$obj = objval($object->property, 'MyClass');
$obj = objval(get_object(), 'MyClass');

// ❌ 错误：非 variable 表达式
$obj = objval(new MyClass(), 'MyClass');  // 不需要
```

### 性能影响

- ✅ `objval()` 是**编译期函数**
- ✅ 不会产生运行时开销
- ✅ 仅在编译阶段进行类型推断
- ✅ 生成的 C++ 代码与普通变量赋值相同

### 与 std:: 类型的区别

| 特性 | std::int/float/bool | objval |
|------|---------------------|--------|
| **用途** | 数值/布尔类型优化 | 对象类型声明 |
| **性能** | ⚡ 高性能（原生类型） | 🐢 标准（ZVAL） |
| **内存** | 8B/1B | 指针（16B+） |
| **时机** | 运行时优化 | 编译期推断 |
| **语法** | `std::int(值)` | `objval(变量，'类名')` |

---## ❌ 不支持的类型

以下类型**不使用**原生类型，仍然使用 ZVAL:

- ❌ `std::string` - 字符串使用 ZVAL (php::Str)
- ❌ `std::array` - 数组使用 ZVAL (php::Array)
- ❌ `std::object` - 对象使用 ZVAL (php::Object)
- ❌ 其他所有类型 - 使用 ZVAL (php::Var)

## 类型映射表

| PHP 类型声明 | C++ 类型 | 底层实现 | 内存 | 性能 | 状态 |
|------------|---------|---------|------|------|------|
| `int` | `php::Int` | `zend_long` | 8B | ⚡ 高性能 | ✅ 原生 |
| `float` | `php::Float` | `double` | 8B | ⚡ 高性能 | ✅ 原生 |
| `bool` | `php::Bool` | `bool` | 1B | ⚡ 高性能 | ✅ 原生 |
| `bigInt` | `php::Var` (Box\<BigInt\>) | `mpz_class` (GMP) | ~32B+ | 🐢 标准 | ✅ 装箱 |
| `decimal` | `php::Var` (Box\<Decimal\>) | `decimal::Decimal` (libmpdec) | ~64B+ | 🐢 标准 | ✅ 装箱 |
| `bigFloat` | `php::Var` (Box\<BigFloat\>) | `mpfr_t` (MPFR) | ~32B+ | 🐢 标准 | ✅ 装箱 |
| `string` | `php::Str` | `zend_string*` | 指针 | 🐢 标准 | ❌ ZVAL |
| `array` | `php::Array` | `zval*` | 指针 | 🐢 标准 | ❌ ZVAL |
| `object` | `php::Object` | `zend_object*` | 指针 | 🐢 标准 | ❌ ZVAL |
| `mixed`/无声明 | `php::Var` | `zval` | 16B | 🐢 标准 | ❌ ZVAL |

## 声明方式对比

| 类型 | C++ 实现 | 声明方式 | 内存 | 性能 | 状态 |
|------|---------|---------|------|------|------|
| **int** | `php::Int` | `std::int(值)`<br>`function foo(int $x)` | 8B | ⚡ 高性能 | ✅ 原生 |
| **float** | `php::Float` | `std::float(值)`<br>`function foo(float $x)` | 8B | ⚡ 高性能 | ✅ 原生 |
| **bool** | `php::Bool` | `std::bool(值)`<br>`function foo(bool $x)` | 1B | ⚡ 高性能 | ✅ 原生 |
| **bigInt** | `php::Var` (Box\<BigInt\>) | `std::bigInt(值)` | ~32B+ | 🐢 标准 | ✅ 装箱 |
| **decimal** | `php::Var` (Box\<Decimal\>) | `std::decimal(值)` | ~64B+ | 🐢 标准 | ✅ 装箱 |
| **bigFloat** | `php::Var` (Box\<BigFloat\>) | `std::bigFloat(值)` | ~32B+ | 🐢 标准 | ✅ 装箱 |
| **string** | `php::Str` | 无<br>`function foo(string $x)` | 指针 | 🐢 标准 | ❌ ZVAL |
| **array** | `php::Array` | 无<br>`function foo(array $x)` | 指针 | 🐢 标准 | ❌ ZVAL |
| **object** | `php::Object` | 无<br>`function foo(object $x)` | 指针 | 🐢 标准 | ❌ ZVAL |
| **mixed** | `php::Var` | 无<br>`function foo($x)` | 16B | 🐢 标准 | ❌ ZVAL |

## 性能差异

### 原生类型（高性能）
```php
function calculate(int $a, int $b): int {
    return $a + $b;  // 使用原生类型，性能提升 100-300 倍
}
```

### ZVAL 类型（标准性能）
```php
function process(string $name, array $data) {
    // 使用 ZVAL，标准 PHP 性能
    echo $name;
    print_r($data);
}
```

## 二元运算类型提升规则

AOT 编译器在执行 `+`、`-`、`*`、`/`、`%` 等二元运算时，按以下优先级确定运算类型：

### 规则优先级

```
BigFloat / Decimal / BigInt 参与
  → 提升到最高精度类型进行计算
  ↓ 未命中

任一边为 Var
  → 两边均转为 Var，使用 ZendVM binary_op 函数
  ↓ 未命中

任一边为 Float
  → 两边均转为 Float (double)
  ↓ 未命中

两边均为 Int
  → 使用 Int (int64_t)
```

### 规则一：Var 主导

当运算数中至少有一边是 `Var` 类型（非 `use native_types` 声明），两边均作为 `Var` 处理，使用 ZendVM 的 `add_function` / `div_function` 等运算函数，完全遵循 PHP 原生类型转换（type juggling）语义。

```php
$a = 10;        // Var，存 int(10)
$b = 2.5;       // Var，存 float(2.5)
$c = $a + $b;   // 两边为 Var → ZendVM 运算 → float(12.5)
```

C++ 代码生成：`int64_t` 和 `double` 值通过 `php::Variant` 的模板构造函数（`phpx.h:557`）隐式转为 `php::Var`，再调用 `Variant::operator+()` → ZendVM 的 `add_function`。

### 规则二：Float 优先于 Int

当两边均为原生类型（通过 `use native_types` 或 `std::int()`/`std::float()` 声明），如果任一边是 Float，则两边均转为 Float 运算。仅当两边都是 Int 才使用整数运算。

```php
use native_types;
$a = 10;        // php::Int
$b = 2.5;       // php::Float
$c = $a + $b;   // Float + Float → double 加法

$d = 5;         // php::Int
$e = 3;         // php::Int
$f = $d + $e;   // Int + Int → int64_t 加法
```

> **注意**：原生类型变量在运算中**不会改变自身类型**。如 `Int += Float` 在 C++ 中执行 `int64_t += double`，结果截断为 int64_t，与 PHP 行为不同（PHP 中变量会变为 float）。这是 `use native_types` 有意为之的语义。

### 规则三：大数类型精度提升

当运算数中包含 `BigInt`、`Decimal` 或 `BigFloat` 时，按精度层级提升：`BigFloat > Decimal > BigInt > Float > Int`。

| 左操作数 | 右操作数 | 结果类型 |
|---------|---------|---------|
| BigInt | BigInt | BigInt（除法 `/` 得 Decimal） |
| BigInt | Decimal | Decimal |
| Decimal | Decimal | Decimal |
| BigFloat | BigInt | BigFloat |
| BigFloat | Decimal | BigFloat |
| BigFloat | BigFloat | BigFloat |
| BigInt | Int | BigInt |
| BigInt | Float | Decimal |
| Decimal | Int | Decimal |
| Decimal | Float | Decimal |
| BigFloat | Int | BigFloat |
| BigFloat | Float | BigFloat |

### 类型提升完整矩阵

| | Int | Float | Var | BigInt | Decimal | BigFloat |
|------|-----|-------|-----|--------|---------|----------|
| **Int** | Int | Float | Var | BigInt | Decimal | BigFloat |
| **Float** | Float | Float | Var | Decimal | Decimal | BigFloat |
| **Var** | Var | Var | Var | Var | Var | Var |
| **BigInt** | BigInt | Decimal | Var | BigInt | Decimal | BigFloat |
| **Decimal** | Decimal | Decimal | Var | Decimal | Decimal | BigFloat |
| **BigFloat** | BigFloat | BigFloat | Var | BigFloat | BigFloat | BigFloat |

> **说明**：Var 行/列全部为 Var，因为 Var 主导规则优先级最高（除 Big* 类型外）。Big* 类型参与时，Var 退让，以高精度类型为准。

### 复合赋值运算符

`+=`、`-=`、`*=`、`/=`、`%=` 等复合赋值运算符遵循相同的类型提升规则，但 RHS 会被转换为 LHS 变量的类型。若 LHS 为 Var，RHS 保持原类型（Var 的 `operator+=` 接管）；若 LHS 为原生类型，RHS 显式转换为该类型。

```php
$a = 10;        // Var
$a += 2.5;      // Var::operator+=(float) → ZendVM → $a 变为 float(12.5)

use native_types;
$b = 10;        // php::Int
$b += 2.5;      // int64_t += double → C++ 隐式截断 → $b = 12 (Int)
```

---

**最后更新**: 2026 年 5 月 26 日  
**适用版本**: PHP AOT Compiler v1.x
