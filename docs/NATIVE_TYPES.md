# AOT 编译器原生类型支持说明

## ⚠️ 重要提示

**AOT 编译器目前仅支持 3 种原生类型（Native Types）**:

1. ✅ `std::int` - 原生整数类型 (zend_long, 8 字节)
2. ✅ `std::float` - 原生浮点类型 (double, 8 字节)
3. ✅ `std::bool` - 原生布尔类型 (bool, 1 字节)

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

| PHP 类型声明 | C++ 类型 | Zend 类型 | 内存 | 性能 | 状态 |
|------------|---------|----------|------|------|------|
| `int` | `php::Int` | `zend_long` | 8B | ⚡ 高性能 | ✅ 原生 |
| `float` | `php::Float` | `double` | 8B | ⚡ 高性能 | ✅ 原生 |
| `bool` | `php::Bool` | `bool` | 1B | ⚡ 高性能 | ✅ 原生 |
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

## 使用建议

### ✅ 推荐使用原生类型的场景
- 数值密集计算
- 循环计数器
- 递归算法
- 性能关键路径

### ⚠️ 使用 ZVAL 的场景
- 字符串处理
- 数组操作
- 对象操作
- 通用业务逻辑

---

**最后更新**: 2024 年 3 月 18 日  
**适用版本**: PHP AOT Compiler v1.x
