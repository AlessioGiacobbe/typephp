# AOT 编译器原生类型支持说明

## ⚠️ 重要提示

**AOT 编译器目前仅支持 3 种原生类型（Native Types）**:

1. ✅ `std::int` - 原生整数类型 (zend_long, 8 字节)
2. ✅ `std::float` - 原生浮点类型 (double, 8 字节)
3. ✅ `std::bool` - 原生布尔类型 (bool, 1 字节)

## ❌ 不支持的类型

以下类型**不使用**原生类型，仍然使用 ZVAL:

- ❌ `std::string` - 字符串使用 ZVAL (php::Str)
- ❌ `std::array` - 数组使用 ZVAL (php::Array)
- ❌ `std::object` - 对象使用 ZVAL (php::Object)
- ❌ 其他所有类型 - 使用 ZVAL (php::Var)

## 类型映射表

| PHP 类型声明 | C++ 类型 | Zend 类型 | 内存 | 是否原生 |
|------------|---------|----------|------|---------|
| `int` | `php::Int` | `zend_long` | 8B | ✅ 是 |
| `float` | `php::Float` | `double` | 8B | ✅ 是 |
| `bool` | `php::Bool` | `bool` | 1B | ✅ 是 |
| `string` | `php::Str` | `zend_string*` | 指针 | ❌ 否 |
| `array` | `php::Array` | `zval*` | 指针 | ❌ 否 |
| `object` | `php::Object` | `zend_object*` | 指针 | ❌ 否 |
| `mixed` | `php::Var` | `zval` | 16B | ❌ 否 |

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
