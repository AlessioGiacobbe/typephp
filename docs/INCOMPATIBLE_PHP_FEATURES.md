# AOT 与 PHP 不兼容特性清单

本文档只记录当前 AOT 编译器与标准 PHP 不兼容或受限的关键特性。

## 程序结构

- 全局作用域不允许可执行语句；只允许声明、`use`、`declare`、常量定义等静态结构。
- 函数和方法内部不允许声明函数。
- 函数和方法内部不允许声明具名类。
- 二进制模式必须定义全局 `main()`。
- `main()` 只允许无参数，或 `(int $argc, array $argv)`。
- `main()` 必须返回 `void`。

## 声明与类型

- 不支持可变变量 `$$var`。
- 不支持 PHP 8.4 property hooks。
- 不支持闭包或箭头函数按引用返回。
- `__construct()` 不允许返回值。
- 参数默认值不允许出现在必填参数之前（`PHP`允许，但会直接丢弃此默认参数）。
- 不支持引用可变参数 `&...$args`。
- 联合类型、交叉类型、`nullable` 类型仍以 `mixed/any` 作为 C++ 表示，但静态阶段会利用已知表达式类型提前拒绝确定不兼容的参数、返回值和属性赋值；动态值仍保留运行时 type check。
- 局部变量类型一旦被静态推断为具体 native 类型，不支持在同一作用域内重新赋值为不兼容类型。
- attribute 参数不支持数组值和 `new` 表达式。

## declare

- 不支持 `declare(ticks=...)`。
- `declare(encoding=...)` 只允许 `UTF-8`。
- `declare(strict_types=...)` 只允许 `strict_types=1`。
- 不支持其他 `declare` 指令。

## 调用与引用

- 闭包和箭头函数不支持引用参数。
- 引用赋值不支持从复杂静态属性表达式建立引用。
- 动态调用、闭包调用等编译期无法确定参数签名的调用，不能自动转换引用参数；需要显式使用 `refval()` 或等价关键词方法 `toRef()`。
- `refval()` / `toRef()` 只接受变量、数组元素或对象属性。
- 带 unpack 且尾部追加 named arguments 的调用会退化为动态调用，不能使用 native call。

## 对象模型

- 禁止子类覆盖父类私有属性。
- `parent::method()` 的方法名必须是字面量。
- 通过变量持有的 clone 对象写入私有 typed property 时，可能无法完全复现 PHP 的私有属性访问语义。
- 为避免 typed property 写入路径引入额外动态检查，native typed property 在右值类型不确定或与属性类型不一致时会退化为 `setProperty()`；部分标量赋值可能遵循 Zend 弱类型转换，而不是 AOT 默认 strict 语义。
- constructor property promotion 的运行时属性可用，但 `ReflectionProperty::isPromoted()` 目前不返回标准 PHP 结果。

## 表达式与控制流

- `echo` 不允许直接使用赋值表达式。
- `match` 的 arm condition 不能是 `match` 表达式。
- `foreach` by reference 的 value 只能是变量。
- `foreach` by reference 不支持 list destructuring。
- 固定 native typed object property 不允许按 PHP 未初始化语义自由 `unset()`。
- native 类型变量执行 `unset()` 不会产生标准 PHP 的变量删除语义。

## 运行时动态能力

- `ClassName::class` 只支持字符串字面量或可静态解析的类名。
- `static::class` 在需要编译期常量类名的位置不支持。
- `__CLASS__` 只允许在 `class` 定义的代码段中使用（`PHP`允许，返回空字符串）。
- `__TRAIT__` 只允许在 `trait` 定义的代码段中使用（`PHP`允许，返回空字符串）。
- 动态属性链、动态类名、动态函数名、动态回调在部分 native 优化路径上会退化或被拒绝。
- `Closure::bind()` 绑定静态闭包访问私有成员时，当前行为与标准 PHP 不完全一致。
- first-class callable 存入 typed nullable `Closure` 属性后，当前存在运行时稳定性限制。
- 所有源文件必须是 `UTF-8` 编码。
