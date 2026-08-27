# AOT/PHP Incompatibility List

This document lists only the key areas in which the current AOT compiler is
incompatible with or more restrictive than standard PHP.

## Program structure

- Executable statements are not allowed at global scope; only static constructs
  such as declarations, `use`, `declare`, and constant definitions are allowed.
- Function declarations are not allowed inside functions or methods.
- Named class declarations are not allowed inside functions or methods.
- Binary mode must define a global `main()`.
- `main()` may either take no parameters or have the signature
  `(int $argc, array $argv)`.
- `main()` must return `void`.

## Declarations and types

- Variable variables `$$var` are not supported.
- PHP 8.5 `#[NoDiscard]` is not supported yet.
- The PHP 8.5 `(void)` cast is supported for explicitly discarding a value; the
  operand is still evaluated and its side effects are preserved, and the cast
  cannot be used in value contexts such as assignments, returns, arguments, or
  conditions.
- Support for PHP 8.5 `clone()` / clone-with requires the linked `libphp` to be
  version 8.5 or later. Public and dynamic properties, private/protected/readonly
  properties, property hooks, call ordering, error propagation, and callable
  paths are all covered by PHPT tests.
- PHP 8.4 property hooks are compiled into AOT getters/setters and register the
  corresponding Zend hook metadata; direct property reads/writes, Reflection,
  and object iteration are all supported. Taking a reference to a hooked
  property is currently not supported.
- PHP 8.4 Reflection Lazy Objects cannot be used with TypePHP AOT classes. AOT
  classes are registered as persistent internal classes, and Zend's
  `zend_object_make_lazy()` explicitly rejects internal classes. Zend PHP user
  classes loaded dynamically at runtime are not subject to this restriction.
- `private(set)` and `protected(set)` asymmetric property visibility is
  supported, including constructor property promotion. Zend-backed objects
  perform the scope check through the PHP 8.4+ class-level object handler and
  preserve the promoted / set-visibility / implicit-final reflection flags;
  Native objects enforce the equivalent scope rules through compile-time access
  checks.
- Final properties declared through constructor promotion are supported, but
  TypePHP requires an explicit `public`, `protected`, or `private` modifier;
  PHP 8.5's implicitly public form, `final int $value`, is not accepted. As a
  TypePHP extension, this syntax is independent of the PHP source-syntax version
  supported by the linked `libphp` and remains available when using a PHP 8.4
  `libphp.so`.
- TypePHP forbids attributes on global or namespaced constant declarations; PHP
  8.5 global constant attributes are out of scope. Class constant attributes are
  not affected by this restriction.
- Returning by reference from closures or arrow functions is not supported.
- PHP 8.5 `static function` expressions in global constants, class constants,
  parameter defaults, or property defaults are not supported yet. Closures
  nested inside initializer expressions are likewise rejected at compile time.
- `__construct()` may not have a return value.
- A parameter with a default value may not appear before a required parameter
  (PHP permits this legacy pattern but treats the former parameter as required).
- Variadic parameters by reference `&...$args` are not supported.
- Union, intersection, and nullable types are still represented as `mixed/any`
  in C++, but the static analysis phase uses known expression types to reject
  definitely incompatible arguments, return values, and property assignments
  ahead of time; dynamic values still retain their runtime type checks.
- Once a local variable's type has been statically inferred as a concrete native
  type, reassigning it to an incompatible type within the same scope is not
  supported.

## declare

- `declare(ticks=...)` is not supported.
- `declare(encoding=...)` accepts only `UTF-8`.
- `declare(strict_types=...)` accepts only `strict_types=1`.
- No other `declare` directives are supported.

## Calls and references

- `exit(message: $value)` is available as a TypePHP named-argument extension; it
  enters the same exit path as the positional form `exit($value)`.
- TypePHP uses strict argument-count rules: non-variadic functions do not accept
  extra arguments beyond the declared signature, and `func_get_args()` does not
  implicitly relax the signature.
- Reference parameters and write-back semantics are supported for ordinary
  functions, ordinary methods, and native direct calls with known signatures;
  do not mistakenly describe the compiler's internal cross-trait dynamic-dispatch
  limitation as "TypePHP does not support reference parameters".
- Closures and arrow functions do not support reference parameters.
- Reference assignment cannot create a reference from a complex static-property
  expression.
- Calls whose argument signature cannot be determined at compile time — dynamic
  calls, closure calls, and the like — cannot convert reference parameters
  automatically; `refval()` or the equivalent keyword method `toRef()` must be
  used explicitly.
- `refval()` / `toRef()` only accept variables, array elements, or object
  properties.
- A call that uses argument unpacking followed by named arguments falls back to
  dynamic dispatch and cannot use the native call path.

## Object model

- Reserved keyword methods such as `toInt()`, `toString()`, and `toArray()` are
  resolved before ordinary object methods; an application method of the same
  name that takes arguments is not called with ordinary object-method semantics.
- `toAny()` and `toRef()` are non-overridable TypePHP keyword methods, and
  ordinary class-like declarations must not define methods with these names
  (method names are case-insensitive, per PHP rules). A Native class may only
  explicitly define a `toAny()` conversion method returning `mixed/any`; no
  implicit conversion is provided. Native classes do not support `toRef()`.
- Fixed-layout typed properties that are not explicitly initialized use the
  zero value of their type and do not preserve Zend PHP's full uninitialized
  state; expressions such as `??` that depend on the uninitialized state may
  therefore behave differently.
- A subclass may not shadow a parent's private property with a `private`
  property of the same name; `public` / `protected` declarations of the same name
  are treated as the same inherited property slot and must still satisfy the
  type, visibility, and `readonly` compatibility requirements.
- To avoid introducing extra dynamic checks in the typed-property write path, a
  native typed property falls back to `setProperty()` when the right-hand side's
  type is unknown or inconsistent with the property type; some scalar
  assignments may then follow Zend's weak type conversion instead of the AOT
  default strict semantics.

## Expressions and control flow

- A `match` arm condition may not itself be a `match` expression.
- The value target in a by-reference `foreach` may only be a variable.
- `foreach` list destructuring does not support binding elements by reference.
- Appending, inserting, `unset()`, and wholesale replacement of `std::vector`,
  `std::map`, and `std::ordered_map` are forbidden during a `foreach`;
  non-structural updates of existing elements can still be done with assignment
  operators.
- Fixed native typed object properties cannot be freely `unset()` with PHP's
  standard uninitialized-property semantics.
- Calling `unset()` on a native-typed variable does not delete the variable as
  it would in standard PHP.

## Runtime dynamic capabilities

- `ClassName::class` only supports string literals or statically resolvable
  class names.
- `static::class` is not supported in positions that require a compile-time
  constant class name.
- `__CLASS__` may only be used within a `class` definition (PHP allows
  it elsewhere and returns an empty string).
- `__TRAIT__` may only be used within a `trait` definition (PHP allows
  it elsewhere and returns an empty string).
- Dynamic property chains, dynamic class names, dynamic function names, and
  dynamic callbacks all go through the Zend runtime fallback and are not
  guaranteed to be natively optimized; reference parameters of dynamic calls
  still require an explicit `refval()` or `toRef()`.
- When `Closure::bind()` binds a static closure that accesses private members,
  the current behavior is not fully consistent with standard PHP.
- Storing a first-class callable in a nullable typed `Closure` property
  currently has runtime stability limitations.
- All source files must be encoded as `UTF-8`.

## Compiler bootstrapping and internal refactoring constraints

This section describes the constraints that apply when the compiler itself is
compiled with TypePHP. These are not additional PHP semantic differences for
user code.

- Before the refactoring, a statically resolvable `$this->method()` call within
  the same core class generated a native C++ direct call. Reference parameters
  were mapped directly to `php::Ref` or a C++ reference, and write-back
  semantics worked normally.
- After splitting the caller and callee into different traits, compiling the
  trait body on its own makes it impossible to determine the final host class
  from the trait's `$this`. The current method resolver may lower a cross-trait
  call to a Zend method call, for example generating
  `this_.call(..., php::ArgList{value})`.
- The `ArgList` of a dynamic method call does not automatically promote ordinary
  arguments to references based solely on the callee wrapper's arginfo. If the
  callee method declares `&$value`, the wrapper fetches the argument through
  `getCallArgByRef()`, while the caller passes an ordinary value; the result is a
  `must be passed by reference` warning, and modifications made by the callee
  cannot be written back to the caller.
- Therefore, cross-trait APIs inside the compiler must not use reference output
  parameters or a protocol in which a passed scalar or array is modified and
  then read by the caller. They should return a result value, a tuple array, or
  a DTO — for example, use `[$type, $class] = resolveTypeDecl(...)` instead of
  `parseTypeDecl(..., &$class)`.
- Internal helpers for string accumulation, array sorting, parse-result output,
  and the like should preferably be designed around pure return values:
  `$code .= format(...)`, `$files = sort(...)`. Only when the call is confirmed
  to stay a native direct call may reference write-back be relied upon.
- Every time a method is moved to a trait, a parent class, or a separate
  component, at least one test covering that call must be recompiled with the
  bootstrapped artifact; running tests only with `bin/tpc.php` cannot reveal
  problems where "the source compiler works but the bootstrapped compiler
  regresses".
