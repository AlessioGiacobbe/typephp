# yield / Generator

TypePHP 将包含 `yield` 或 `yield from` 的函数和方法编译为 Fiber generator。调用这类函数时不会立即执行函数体，而是返回一个 `TypePHP\FiberGenerator` 对象；第一次迭代、调用 `current()`、`valid()`、`send()` 或 `throw()` 时才创建并启动 Fiber。

生成的 C++ 函数体运行在 Fiber 中。每次 `yield` 都通过 `Fiber::suspend()` 将 key/value 交给迭代驱动方，并在 `next()`、`send()` 或 `throw()` 时恢复原来的 C++ 调用栈。运行时使用 `NEW`、`SUSPENDED`、`CLOSED_RETURNED` 和 `CLOSED_FAILED` 四种状态区分未启动、挂起、正常返回和异常关闭。

## 迭代互操作

以下组合已经可以工作：

| 驱动方 | 被迭代对象 |
|---|---|
| TypePHP Native `foreach` | TypePHP Native generator |
| TypePHP Native `foreach` | 动态 PHP 返回的 Zend `Generator` |
| ZendVM `foreach` | TypePHP Native generator 返回的 `TypePHP\FiberGenerator` |
| TypePHP `yield from` | 数组、`Iterator`、`IteratorAggregate`、Zend `Generator`、`TypePHP\FiberGenerator` |

`TypePHP\FiberGenerator` 实现了 `Iterator`，其接口签名与 PHP 一致：

```php
rewind(): void
next(): void
valid(): bool
current(): mixed
key(): mixed
send(mixed $value): mixed
throw(Throwable $exception): mixed
getReturn(): mixed
```

当前状态机已经覆盖首次和重复 `rewind()`、未启动时的 `next()`/`send()`/`throw()`、关闭后的调用、自动整数 key、正常及异常 `getReturn()`、异常传播和挂起状态析构时执行 `finally`。

## 与 PHP 的差异

### 不是 Zend Generator

TypePHP generator 返回的是 `TypePHP\FiberGenerator`，不是 PHP 内置的 `Generator`：

```php
$generator instanceof Iterator;  // true
$generator instanceof Generator; // false
```

因此存在以下差异：

- generator 函数不能声明精确返回类型 `Generator`。
- 可以使用 `Iterator`、`Traversable`、`iterable`、`object`、`mixed`，或包含这些兼容类型的联合类型。
- `ReflectionGenerator` 只接受 Zend `Generator`，不能用于 `TypePHP\FiberGenerator`。
- `get_class()`、Reflection class 信息和异常栈中的类名与 Zend `Generator` 不同。
- 不保证 `var_dump()`、调试属性、序列化错误、clone 行为及内部对象布局与 Zend `Generator` 一致。
- `TypePHP\FiberGenerator` 是运行时内部实现类型，不应由业务代码直接实例化、继承、clone 或序列化。

### 不支持引用 Generator

以下 PHP 语法暂不支持：

```php
function &values(): iterable
{
    yield $value;
}

foreach (values() as &$value) {
}
```

TypePHP 不支持：

- generator 函数或方法按引用返回。
- by-reference yield 语义。
- 对 generator 使用按引用 `foreach`。
- 通过 generator 维持元素引用身份。

`current()`、`send()`、`throw()` 和 `getReturn()` 都按普通 PHP 值返回；运行时会解除 `INDIRECT` 和 `REFERENCE` 包装，不会返回引用容器。

### 参数限制

TypePHP generator 暂不支持以下参数声明：

- 按引用参数，例如 `function values(&$value)`。
- 可变参数，例如 `function values(...$values)`。
- 按引用可变参数，例如 `function values(&...$values)`。

普通参数、默认值、联合类型参数、对象参数和方法中的 `$this` 可以使用。参数类型检查及 constructor property promotion 在 generator 对象创建时执行，函数体仍保持延迟执行。

### Traversable 边界

`yield from` 和 TypePHP Native 对象 `foreach` 当前通过 `Iterator`/`IteratorAggregate` 接口方法驱动对象，而不是直接使用所有 Zend iterator handlers。

这意味着：

- 用户态 `Iterator`、`IteratorAggregate` 和 Zend `Generator` 可以迭代。
- TypePHP `yield from` 会检测 `IteratorAggregate::getIterator()` 返回自身或形成对象环，并抛出异常。
- TypePHP Native 对象 `foreach` 的 `IteratorAggregate` 展开路径尚未加入同样的环检测；`getIterator()` 返回自身或形成对象环时可能无限循环，应避免这种实现。
- 某些扩展提供的内部 `Traversable` 如果既不实现 `Iterator`，也不实现 `IteratorAggregate`，可能无法按 PHP 原生 `foreach` 的方式迭代。
- 非法 `getIterator()` 返回值的异常类型、消息文本和栈信息可能与 ZendVM 不完全一致。

### Fiber 可观察差异

Zend `Generator` 是 ZendVM 的专用执行对象；TypePHP generator 使用 PHP Fiber 保存完整 C/C++ 栈，因此：

- 运行环境必须提供 PHP Fiber。
- 异常栈中可能出现 `Fiber`、内部 closure 或 TypePHP runtime frame。
- 文件名、行号和调用栈形状不保证与 `ReflectionGenerator` 或 Zend Generator 完全相同。
- 正常迭代、异常传播和挂起析构的 `finally` 已有回归测试，但复杂对象环、请求关闭、进程退出及析构函数再次抛出异常时的执行顺序仍可能与 Zend Generator 不同。
- Fiber 被强制关闭时使用 Zend 内部 graceful-exit 展开 C++ 栈；该对象不是业务代码可捕获或依赖的公开异常类型。

### yield from 差异

数组、普通 Iterator 和 generator 的 key/value 转发、generator return value、`send()` 和 `throw()` 委托已经实现，但底层实现不是 Zend `yield from` opcode：

- 委托通过 `rewind()`、`valid()`、`key()`、`current()`、`next()`、`send()`、`throw()` 和 `getReturn()` 方法完成。
- 自定义 Iterator 方法产生的副作用、异常栈和调用次数应避免依赖 Zend Generator 的内部实现细节。
- 非 generator Iterator 的 `yield from` 结果为 `null`；只有 Zend `Generator` 和 `TypePHP\FiberGenerator` 会读取 `getReturn()`。

## 性能差异

Fiber generator 不会改变普通数组或普通容器 `foreach` 的生成代码。只有实际创建并驱动 generator 时才产生额外成本。

每个 yield 目前需要：

- Fiber suspend/resume。
- `Iterator` 方法调用。
- generator 状态和对象属性读写。
- key/value payload 数组的创建与释放。
- `yield from` 场景中的额外委托调用。

因此 TypePHP Fiber generator 通常比 Native C++ 数组 `foreach` 慢，也可能比 Zend 专用 Generator opcode 慢。高频、短元素迭代应优先使用数组或 Native 容器；generator 更适合延迟计算、流式处理和需要保存完整 Native 调用栈的场景。

## 不兼容清单

当前不能依赖以下 PHP 行为：

- 返回对象是 Zend `Generator`。
- `instanceof Generator`。
- 返回类型声明为精确的 `Generator`。
- `ReflectionGenerator`。
- generator 按引用返回、by-reference yield 或按引用 foreach。
- generator 的按引用参数或可变参数。
- clone、序列化、调试输出及内部属性与 Zend Generator 相同。
- 所有内部扩展 `Traversable` 都能通过 Zend iterator handlers 迭代。
- Fiber 关闭、复杂析构和进程退出时与 Zend Generator 完全相同的栈及析构顺序。
