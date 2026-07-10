# yield / generator 限制

TypePHP 的 generator 基于 PHP Fiber 运行。generator 函数或方法会返回 `TypePHP\FiberGenerator`，该对象实现 `Iterator`，但不是 PHP 内置 `Generator` 实例。

## 不支持

- 不支持声明返回类型为 `Generator`；请使用 `Iterator`、`Traversable`、`iterable`、`object`、`mixed`，或省略返回类型。
- 不支持按引用返回的 generator，例如 `function &gen() { yield 1; }`。
- 不支持 generator 参数按引用传递。
- 不支持 generator 可变参数。
- 不支持 by-reference yield 语义。
- 不支持在动态 PHP 脚本中使用 `foreach` 直接遍历 TypePHP Native generator 返回的 `TypePHP\FiberGenerator`。
- 不保证 `instanceof Generator`、`ReflectionGenerator`、`Generator` 内部实现细节与 Zend 原生 generator 兼容。

## 受限行为

- `yield from` 可以转发数组和 `Traversable` 的 key/value；委托对象是 generator 时可以读取其 return value。
- TypePHP Native `foreach` 可以遍历动态 PHP 返回的 Zend 原生 generator；反向由 ZendVM `foreach` 驱动 TypePHP Native generator 暂不支持。
- generator 的执行依赖 Fiber；如果当前 PHP 运行环境禁用或缺失 Fiber，则无法运行。
- generator body 在 Fiber 内执行，析构、异常传播、force-close 与 Zend 原生 generator 可能存在边界差异。
