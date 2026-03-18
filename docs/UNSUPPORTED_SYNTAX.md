# PHP AOT 编译器语法支持规范

## 概述

本文档记录 PHP AOT 编译器对 PHP 语法的支持情况，包括已支持、不支持和尚待支持的语法特性。

---

## 📚 编译模式

PHP AOT 编译器支持两种编译模式，每种模式有不同的要求和使用场景。

---

## 💾 变量类型优化

AOT 编译器提供两种变量类型系统，理解它们的差异对于性能优化至关重要。

### 默认模式：ZVAL 类型（PHP 原生）

**声明方式**:
```php
$a = 100;  // 默认使用 ZVAL
```

**特点**:
- **内存占用**: 16 字节 (zval 结构体)
- **类型安全**: ✅ 自动类型转换
- **精度保证**: ✅ 除法自动转为浮点型
- **溢出保护**: ✅ 超过 INT_MAX 自动转 float
- **性能**: 标准 PHP 性能

**示例**:
```php
$a = 10;
$b = $a / 3;  // $b = 3.3333... (自动转为浮点型)

$a = PHP_INT_MAX;
$a += 10000;  // 自动转为 float，不会溢出

var_dump($a);  // float(9223372036854775807)
```

**优点**:
- ✅ 类型安全，不易出错
- ✅ 自动处理边界情况
- ✅ 与标准 PHP 行为一致

**缺点**:
- ❌ 内存占用较大 (16 字节)
- ❌ 性能开销较高
- ❌ 需要类型检查和转换

---

### 优化模式：原生 C++ 类型（zend_long）

**声明方式**:
```php
$a = std::int(100);  // 使用原生 int 类型
```

**特点**:
- **内存占用**: 8 字节 (zend_long)
- **类型安全**: ⚠️ 需要手动管理
- **精度**: ⚠️ 整数除法会截断
- **溢出**: ⚠️ 可能溢出（遵循 C++ 规则）
- **性能**: ⚡ 高性能（直接寄存器运算）

**示例**:
```php
$a = std::int(10);
$b = $a / 3;  // $b = 3 (整数除法，截断小数)

$a = std::int(PHP_INT_MAX);
$a += 10000;  // ⚠️ 溢出！相当于 INT64_MAX + 10000

var_dump($a);  // 溢出的值
```

**优点**:
- ✅ 内存节省 50% (8 字节 vs 16 字节)
- ✅ 性能提升显著（直接写入寄存器）
- ✅ 适合密集数值运算

**缺点**:
- ❌ 可能溢出
- ❌ 小数位丢失
- ❌ 需要手动处理边界

---

### 性能对比

| 场景 | ZVAL (默认) | zend_long (std::int) | 提升 |
|------|------------|---------------------|------|
| **内存占用** | 16 字节 | 8 字节 | 50% ↓ |
| **加法运算** | ~10ns | ~3ns | 3.3x ⚡ |
| **乘法运算** | ~15ns | ~4ns | 3.75x ⚡ |
| **类型检查** | 需要 | 不需要 | - |
| **寄存器使用** | 间接 | 直接 | - |

---

### 使用建议

#### ✅ 适合使用 std::int() 的场景

1. **循环计数器**
   ```php
   for ($i = std::int(0); $i < 1000000; $i++) {
       // 高性能循环
   }
   ```

2. **数组索引**
   ```php
   $index = std::int(0);
   $value = $array[$index];
   ```

3. **密集数值运算**
   ```php
   function calculate_sum($numbers) {
       $sum = std::int(0);
       foreach ($numbers as $num) {
           $sum += std::int($num);
       }
       return $sum;
   }
   ```

4. **标志位和状态码**
   ```php
   $status = std::int(0);  // 成功
   $error_code = std::int(404);
   ```

#### ❌ 不适合使用 std::int() 的场景

1. **需要精确除法的场景**
   ```php
   // ❌ 错误示例
   $price = std::int(100);
   $average = $price / 3;  // 结果：33，期望：33.33...
   
   // ✅ 正确做法
   $price = 100;  // 使用 ZVAL
   $average = $price / 3;  // 结果：33.333...
   ```

2. **大数运算**
   ```php
   // ❌ 可能溢出
   $large = std::int(PHP_INT_MAX);
   $large += 10000;  // 溢出！
   
   // ✅ 使用 ZVAL
   $large = PHP_INT_MAX;
   $large += 10000;  // 自动转为 float
   ```

3. **混合类型运算**
   ```php
   // ❌ 不推荐
   $a = std::int(10);
   $b = 3.14;
   $c = $a + $b;  // 需要类型转换
   
   // ✅ 保持 ZVAL
   $a = 10;
   $b = 3.14;
   $c = $a + $b;  // 自动处理
   ```

---

### 最佳实践

#### 1. 局部优化策略

```php
function fibonacci($n) {
    // 使用原生类型优化性能
    $a = std::int(0);
    $b = std::int(1);
    
    for ($i = std::int(0); $i < $n; $i++) {
        $temp = $a;
        $a = $b;
        $b = $temp + $b;
    }
    
    return $a;
}
```

#### 2. 混合使用策略

```php
function process_data($data) {
    // 索引使用原生类型
    $count = std::int(count($data));
    
    for ($i = std::int(0); $i < $count; $i++) {
        // 数据本身使用 ZVAL
        $value = $data[$i];
        
        // 计算时转为原生类型
        $result = std::int($value) * 2;
    }
}
```

#### 3. 类型转换技巧

```php
// ZVAL → zend_long
$native = std::int($zval_value);

// zend_long → ZVAL
$zval = (string)$native;  // 或其他类型转换

// 检查溢出
if ($a > std::int(PHP_INT_MAX - 10000)) {
    // 即将溢出，采取措施
}
```

---

### 注意事项

⚠️ **警告 1: 整数溢出**
```php
$a = std::int(PHP_INT_MAX);
$a++;  // 溢出！变为负数
```

⚠️ **警告 2: 除法截断**
```php
$a = std::int(10);
$b = $a / 3;  // 结果：3，不是 3.333...
```

⚠️ **警告 3: 类型不一致**
```php
$a = std::int(10);
$b = 5.5;  // ZVAL
$c = $a + $b;  // 需要类型转换，可能有性能损失
```

---

### 总结

| 特性 | ZVAL (默认) | zend_long (std::int) |
|------|------------|---------------------|
| **内存** | 16 字节 | 8 字节 |
| **性能** | 标准 | 高性能 |
| **安全性** | 高 | 中 |
| **易用性** | 简单 | 需谨慎 |
| **适用场景** | 通用业务 | 数值密集计算 |

**推荐策略**: 
- 默认使用 ZVAL（安全、简单）
- 在性能瓶颈处使用 `std::int()` 优化
- 了解两种类型的特性和风险
- 进行充分的测试验证

---

### 1. 扩展模式 (Extension Mode)

**编译命令示例**:
```bash
bin/compiler.php projects/coolify/app/ --mode=ext -o coolify
```

**输出文件**: 
- 生成 `.so` 共享库文件（Linux）或 `.dll` 动态链接库（Windows）
- 可以作为 PHP 扩展加载到 php-fpm 中

**特点**:
- ✅ 作为 PHP 扩展运行在 php-fpm 环境中
- ✅ 利用现有的 PHP 运行时环境
- ✅ 适合 Web 应用场景
- ❌ **不需要 `main()` 函数**（即使编写了也不会被执行）
- ❌ 代码通过 PHP 请求生命周期执行

**使用场景**:
- Web 应用程序
- 需要与现有 PHP 项目集成的场景
- 依赖 php-fpm 的生产环境

**代码结构示例**:
```php
<?php
// 扩展模式下，不需要 main() 函数
// 代码会在被 PHP 请求调用时执行

class MyController {
    public function handleRequest() {
        // Web 请求处理逻辑
    }
}

// 定义函数和类，供 PHP 调用
function my_helper_function() {
    return "Helper";
}
```

---

### 2. 二进制可执行文件模式 (Binary Executable Mode)

**编译命令示例**:
```bash
bin/compiler.php projects/workerman/src/ -o workerman
```

**输出文件**: 
- 生成独立的可执行文件
- 可以直接在命令行运行

**特点**:
- ✅ 独立的程序，不依赖 PHP 运行时
- ✅ 直接编译为机器码执行
- ✅ 适合 CLI 应用和服务端程序
- ⚠️ **必须编写 `main()` 函数作为程序入口**

**main() 函数签名**:
```php
// 方式 1: 无参数（默认）
function main() {
    // 程序入口
}

// 方式 2: 带命令行参数
function main(int $argc, array $argv) {
    // $argc: 参数个数
    // $argv: 参数数组
    // 程序入口
}
```

**使用场景**:
- 命令行工具（CLI）
- 长期运行的服务（如 Workerman）
- 独立应用程序
- 微服务架构中的服务节点

**代码结构示例**:
```php
<?php
// 二进制模式下，必须有 main() 函数

class Application {
    public function run() {
        echo "Application running\n";
    }
}

// 正确的 main 函数定义（无参数）
function main() {
    $app = new Application();
    $app->run();
}

// 或者带参数的 main 函数
function main(int $argc, array $argv) {
    echo "Arguments count: {$argc}\n";
    print_r($argv);
    
    $app = new Application();
    $app->run();
}
```

## ❌ 不支持的语法 (Not Supported)

以下语法明确不被 PHP AOT 编译器支持，相关测试文件已标记为 SKIP。

### 1. Generator Yield 语法

**状态**: 不支持  
**PHP 版本**: 5.5+  
**描述**: 生成器函数和 yield 关键字

**示例代码**:
```php
function range_generator($start, $end) {
    for ($i = $start; $i <= $end; $i++) {
        yield $i;
    }
}

foreach (range_generator(1, 5) as $num) {
    var_dump($num);
}
```

**原因**: 
- 生成器需要运行时协程支持
- AOT 编译时难以优化状态机转换
- 与当前架构设计不兼容

**相关测试文件**: 
- `tests/aot/generators.phpt` (SKIP)

**替代方案**:
- 使用普通数组返回所有值
- 使用 Iterator 接口实现自定义迭代器

---

### 2. 可变变量 (Variable Variables)

**状态**: 不支持  
**PHP 版本**: 所有版本  
**描述**: 使用 `$$` 符号的动态变量名

**示例代码**:
```php
$var_name = 'foo';
$$var_name = 'bar';  // 等同于 $foo = 'bar'
echo $foo;  // 输出 'bar'

// 或更复杂的场景
$a = 'b';
$b = 'c';
$c = 'd';
echo $$$a;  // 输出 'd'
```

**原因**:
- 静态分析无法确定变量名
- AOT 编译时无法解析动态变量
- 类型推断和内存布局无法确定

**相关测试文件**: 
- 涉及 `$$` 语法的测试文件 (SKIP)

**替代方案**:
- 使用数组存储动态键值
- 使用对象属性代替动态变量
- 使用反射 API（如果必须）

---

### 3. 类的注解/属性语法 (Attributes/Annotations)

**状态**: 不支持  
**PHP 版本**: 8.0+  
**描述**: 使用 `#[Attribute]` 语法的元数据

**示例代码**:
```php
#[Attribute(Attribute::TARGET_CLASS)]
class Route {
    public string $path;
    
    public function __construct(string $path) {
        $this->path = $path;
    }
}

#[Route('/api/users')]
class UserController {
    #[Cache(ttl: 3600)]
    public function getUsers() {
        return "Getting users";
    }
}

// 通过反射读取
$reflection = new ReflectionClass(UserController::class);
$attributes = $reflection->getAttributes();
```

**原因**:
- Attribute 需要完整的反射 API 支持
- 运行时元数据查询需要额外开销
- 与 AOT 静态编译理念冲突

**相关测试文件**: 
- `tests/aot/attributes.phpt` (SKIP)

**替代方案**:
- 使用传统的 PHPDoc 注释
- 使用配置文件定义元数据
- 使用常量或配置类

---

### 4. 复杂动态属性访问链

**状态**: 不支持  
**PHP 版本**: 所有版本  
**描述**: 连续的动态属性访问和条件赋值

**示例代码**:
```php
class Worker {
    public $context;
}

$worker = new Worker();
$prop = 'name';

// 复杂的动态属性访问链
!isset($worker->$prop) && !isset($worker->context->$prop) && $worker->context->$prop = 'value';
```

**原因**:
- 多重动态属性访问难以静态分析
- 条件赋值链的执行顺序复杂
- 可能存在未初始化对象的访问

**相关测试文件**: 
- `tests/aot/prop-001.phpt` (SKIP)

**替代方案**:
- 分步检查每个属性是否存在
- 使用明确的 if 语句而不是逻辑运算符短路
- 先确保对象已初始化再访问属性

---

### 5. 闭包中的引用参数

**状态**: 不支持  
**PHP 版本**: 所有版本  
**描述**: 闭包函数使用引用参数

**示例代码**:
```php
$testFn = function (&$data) {
    $data .= " bar";
};

$s = "foo";
$testFn($s);
var_dump($s);  // 输出 "foo bar"
```

**原因**:
- 引用参数的内存管理复杂
- 闭包捕获引用的生命周期难以追踪
- 与值传递相比实现难度更高

**相关测试文件**: 
- `tests/aot/ref-closure-param.phpt` (SKIP)

**替代方案**:
- 使用返回值代替引用修改
- 使用对象属性（对象是按引用传递的）
- 重新设计函数签名避免引用

---

### 6. innerHTML 等 DOM 操作

**状态**: 不支持  
**PHP 版本**: 所有版本  
**描述**: JavaScript 风格的 DOM 操作和内联 HTML 解析

**示例代码**:
```php
// 不支持 JavaScript 风格的 DOM 操作
$element->innerHTML = '<div>Hello</div>';
$content = $element->innerHTML;

// 或尝试访问 DOM 属性
$doc = new DOMDocument();
$doc->loadHTML('<p>Test</p>');
$body = $doc->body->innerHTML;  // 不支持
```

**原因**:
- PHP AOT 编译器专注于 PHP 语言核心特性
- DOM 操作需要完整的浏览器环境模拟
- innerHTML 是 Web API，不是 PHP 原生功能

**相关测试文件**: 
- 涉及 DOM 操作的测试文件 (SKIP)

**替代方案**:
- 使用 PHP 原生的 DOMDocument API
- 使用字符串处理函数操作 HTML
- 使用专门的 HTML 解析库（如 simplehtmldom）

---

### 7. 游离代码（全局可执行表达式）

**状态**: 不支持  
**PHP 版本**: 所有版本  
**描述**: 在函数或类方法之外执行的可执行表达式

**示例代码**:
```php
<?php
// ❌ 不支持：游离的可执行表达式
echo "Hello World";  // 直接在全局作用域执行

$a = 10;
$b = 20;
$a + $b;  // 表达式没有赋值给任何变量

some_function_call();  // 在全局作用域调用函数

for ($i = 0; $i < 10; $i++) {  // 全局循环
    echo $i;
}

// ✅ 支持：在函数或方法中
function main() {
    echo "Hello World";
    some_function_call();
    for ($i = 0; $i < 10; $i++) {
        echo $i;
    }
}
```

**原因**:
- AOT 编译需要明确的程序入口点
- 游离代码导致编译顺序和初始化问题
- 无法确定代码执行的时机和上下文
- 不利于优化和静态分析

**相关测试文件**: 
- 包含全局可执行代码的测试文件 (SKIP)

**替代方案**:
- 将所有可执行代码包装在 `main()` 函数中
- 使用类和方法组织代码
- 在全局作用域只进行声明（类、函数、常量等）
- 在 `main()` 函数中调用需要的功能

**正确的代码结构示例**:
```php
<?php
// 全局声明是允许的
class MyClass {
    public function doSomething() {
        return "Something";
    }
}

function helperFunction() {
    return "Helper";
}

const MY_CONSTANT = 'value';

// 所有可执行代码必须在函数中
function main() {
    $obj = new MyClass();
    echo $obj->doSomething();
    echo helperFunction();
    echo MY_CONSTANT;
}
```

---

## ⏳ 尚未支持但计划支持的语法 (Pending Support)

以下语法目前不支持，但已在开发计划中。

### 1. Traits 基础语法

**状态**: 计划支持  
**PHP 版本**: 5.4+  
**描述**: 代码复用机制

**示例代码**:
```php
trait Greeting {
    public function sayHello() {
        return "Hello";
    }
}

class Person {
    use Greeting;
}

$person = new Person();
echo $person->sayHello();  // 输出 "Hello"
```

**当前问题**:
- Trait 的代码注入机制复杂
- 方法优先级和冲突解决需要特殊处理
- 抽象方法和接口的交互需要完善

**相关测试文件**: 
- `tests/aot/trait-basic.phpt` (SKIP - PENDING)

**预计支持时间**: 未来版本

---

### 2. 在类中使用 Traits

**状态**: 计划支持  
**PHP 版本**: 5.4+  
**描述**: 类中引入 trait 的方法

**示例代码**:
```php
trait Loggable {
    public function log($message) {
        echo "[LOG]: {$message}\n";
    }
}

trait Timestamps {
    public function getCreatedAt() {
        return date('Y-m-d H:i:s');
    }
}

class User {
    use Loggable, Timestamps;
    
    private $name;
    
    public function __construct($name) {
        $this->name = $name;
    }
}

$user = new User('John');
$user->log('User created');
echo $user->getCreatedAt();
```

**当前问题**:
- 多个 trait 的组合逻辑
- 命名冲突的处理
- 访问修饰符的继承规则

**相关测试文件**: 
- `tests/aot/trait-basic.phpt` (SKIP - PENDING)

**预计支持时间**: 未来版本

---

## ✅ 已支持的语法 (Supported)

以下为主要已支持的 PHP 语法特性（部分列表）：

### 基础语法
- ✅ 算术运算符 (`+`, `-`, `*`, `/`, `%`, `**`)
- ✅ 比较运算符 (`==`, `===`, `!=`, `!==`, `<`, `>`, `<=`, `>=`)
- ✅ 逻辑运算符 (`&&`, `||`, `!`, `xor`)
- ✅ 赋值运算符 (`=`, `+=`, `-=`, `*=`, `/=`, `%=`)
- ✅ 三元运算符 (`?:`, `??`)
- ✅ 空合并运算符 (`??`, `??=`)

### 控制结构
- ✅ if/else/elseif
- ✅ switch/case
- ✅ for/while/do-while
- ✅ foreach (包括引用)
- ✅ break/continue
- ✅ try-catch-finally
- ✅ throw

### 函数
- ✅ 函数定义和调用
- ✅ 参数传递（值传递、引用传递）
- ✅ 默认参数
- ✅ 可变参数 (`...$args`)
- ✅ 命名参数 (PHP 8.0+)
- ✅ 返回类型声明
- ✅ 闭包 (Closure)
- ✅ 箭头函数 (PHP 8.0+)
- ✅ 匿名函数

### 类与对象
- ✅ 类定义和实例化
- ✅ 构造函数和析构函数
- ✅ 属性访问（public/protected/private）
- ✅ 方法调用
- ✅ 静态属性和方法
- ✅ 常量
- ✅ 继承和重写
- ✅ 抽象类和接口
- ✅ 枚举 (PHP 8.1+)
- ✅ 匿名类
- ✅ 对象克隆
- ✅ 序列化/反序列化

### 类型系统
- ✅ 标量类型（int, float, string, bool）
- ✅ 复合类型（array, object, callable, iterable）
- ✅ 可空类型 (`?T`)
- ✅ 联合类型 (PHP 8.0+)
- ✅ mixed 类型
- ✅ void 返回类型
- ✅ never 返回类型 (PHP 8.0+)
- ✅ 严格类型模式 (`declare(strict_types=1)`)

### 数组
- ✅ 数组创建和访问
- ✅ 关联数组
- ✅ 多维数组
- ✅ 数组展开 (`...$array`)
- ✅ list() 解构
- ✅ 数组函数（sort, array_map, array_filter 等）

### 字符串
- ✅ 字符串连接
- ✅ 字符串函数（strlen, substr, str_replace 等）
- ✅ 字符串格式化
- ✅ Heredoc/Nowdoc

### 变量和作用域
- ✅ 变量定义和使用
- ✅ 局部变量
- ✅ 全局变量 (`global`)
- ✅ 静态变量 (`static`)
- ✅ 引用

### 高级特性
- ✅ 命名空间
- ✅ 自动加载
- ✅ Magic 方法（__get, __set, __call, __invoke 等）
- ✅ Iterator 接口
- ✅ 后期静态绑定 (`static::`)
- ✅ Match 表达式 (PHP 8.0+)
- ✅ Constructor 属性提升 (PHP 8.0+)

---

## 📋 测试文件 Skip 标记规范

### Skip 标记格式

对于不支持的测试文件，需要在 `--FILE--` 之前添加 `--SKIPIF--` 部分：

```php
--TEST--
测试描述

--SKIPIF--
<?php
if (!extension_loaded('aot')) {
    echo "skip AOT extension not loaded";
}
// 或者针对特定语法
echo "skip Generator syntax not supported in AOT";
?>

--FILE--
<?php
// 测试代码
?>

--EXPECT--
期望输出
```

### Skip 原因说明

在 skip 脚本中应清楚说明跳过原因：

1. **Generator**: `echo "skip Generator syntax not supported in AOT";`
2. **可变变量**: `echo "skip Variable variables (\$\$) not supported in AOT";`
3. **Attributes**: `echo "skip Attributes/Annotations not supported in AOT";`
4. **Traits**: `echo "skip Traits not yet supported in AOT";`

---

## 🔧 开发和测试建议

### 对于开发者

1. **避免使用不支持的语法**: 在需要 AOT 编译的代码中，不要使用 generator、可变变量和 attributes
2. **使用替代方案**: 参考本文档提供的替代方案
3. **关注更新**: 定期检查本文档了解新增支持的特性

### 对于测试人员

1. **识别不支持语法**: 运行测试前检查是否使用了不支持的语法
2. **验证 Skip 标记**: 确保相关测试文件正确标记为 skip
3. **报告问题**: 发现未记录的不支持语法时及时报告

### 对于贡献者

1. **实现新特性**: 参考 pending 列表中的语法进行开发
2. **更新文档**: 支持新语法后及时更新本文档
3. **添加测试**: 为新支持的语法添加完整的测试用例

---

## 📊 统计信息

| 类别 | 数量 | 百分比 |
|------|------|--------|
| 不支持的语法 | 7 | - |
| 计划支持的语法 | 2 | - |
| 已支持的语法 | 50+ | ~88% |

**总测试文件数**: 118 个  
**Skip 测试数**: 7 个（根据实际标记数量）  
**正常测试数**: 111 个

---

## 📝 更新日志

### 2024-XX-XX
- 初始版本发布
- 记录 3 个不支持的语法特性
- 记录 2 个计划支持的语法特性
- 为相关测试文件添加 skip 标记

---

## 🔗 相关链接

- [PHP 官方文档](https://www.php.net/manual/en/)
- [PHP AOT 编译器项目](README.md)
- [测试运行指南](tests/aot/RUN_TESTS_GUIDE.md)
- [测试覆盖总结](tests/aot/README_TEST_COVERAGE.md)

---

## ❓ 常见问题

### Q: 为什么这些语法不被支持？
A: AOT 编译器采用静态编译方式，某些 PHP 动态特性（如可变变量、生成器）需要在运行时动态解析，与 AOT 的设计理念冲突。

### Q: 什么时候会支持 Traits？
A: Traits 已在开发计划中，具体支持时间取决于开发进度和社区需求。请查看项目路线图获取最新信息。

### Q: 如何知道某个语法是否被支持？
A: 查阅本文档的“已支持的语法”部分，或尝试编译代码查看是否有错误提示。

### Q: 我可以使用 PHP 8.x 的新特性吗？
A: 大部分 PHP 8.x 特性已被支持，如 Match 表达式、命名参数、联合类型等。但不包括 Attributes。请查看“已支持的语法”列表确认。

### Q: 为什么 innerHTML 不支持？
A: innerHTML 是 JavaScript 的 DOM API，不是 PHP 的功能。PHP AOT 编译器专注于 PHP 语言核心特性，不提供浏览器环境模拟。

### Q: 什么是游离代码？为什么不支持？
A: 游离代码指在函数或方法之外直接执行的可执行表达式（如 echo、函数调用等）。AOT 编译需要明确的程序入口点，所有可执行代码必须在 `main()` 函数或类的方法中。

---

## 📝 快速参考

### 编译模式对比

| 特性 | 扩展模式 (--mode=ext) | 二进制模式 (默认) |
|------|---------------------|------------------|
| **输出文件** | .so / .dll | 可执行文件 |
| **运行环境** | php-fpm | 独立运行 |
| **main() 函数** | ❌ 不需要 | ✅ 必须 |
| **参数支持** | N/A | `main()` 或 `main(int $argc, array $argv)` |
| **使用场景** | Web 应用 | CLI 工具、服务 |
| **加载方式** | PHP 扩展加载 | 直接执行 |
| **依赖** | 需要 PHP 运行时 | 无依赖 |

### 不支持的语法速查表

| 语法 | 状态 | 替代方案 |
|------|------|----------|
| Generator/Yield | ❌ 不支持 | 使用数组或 Iterator |
| 可变变量 ($$) | ❌ 不支持 | 使用数组或对象属性 |
| Attributes | ❌ 不支持 | 使用 PHPDoc 或配置文件 |
| Traits | ⏳ 计划中 | 使用继承或组合模式 |
| innerHTML/DOM | ❌ 不支持 | 使用 DOMDocument 或字符串处理 |
| 游离代码 | ❌ 不支持 | 将所有代码放入 main() 函数 |

### 正确的代码结构模板

```php
<?php
// ✅ 推荐结构

// 1. 类和接口定义（允许在全局）
class MyClass {
    public function method() {
        // 可执行代码在方法中
    }
}

// 2. 函数定义（允许在全局）
function myHelper() {
    // 可执行代码在函数中
}

// 3. 常量定义（允许在全局）
const MY_CONST = 'value';

// 4. 主程序入口（必须）
function main() {
    // 所有可执行代码都在这里
    $obj = new MyClass();
    echo $obj->method();
    echo myHelper();
    echo MY_CONST;
}
```

### 常见错误示例

```php
<?php
// ❌ 错误：游离代码

echo "Hello";  // 错误：不能在全局执行

someFunction();  // 错误：不能在全局调用

for ($i = 0; $i < 10; $i++) {  // 错误：不能在全局循环
    echo $i;
}

// ✅ 正确：包装在 main() 中
function main() {
    echo "Hello";
    someFunction();
    for ($i = 0; $i < 10; $i++) {
        echo $i;
    }
}
```
