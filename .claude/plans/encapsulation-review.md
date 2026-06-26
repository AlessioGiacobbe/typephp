# Encapsulation Review

## Summary

| Metric | Value |
|--------|-------|
| Entity classes with all-public fields | 9/9 (100%) |
| FunctionContext public properties | 26 (all mutable) |
| CompilerBase private methods | 5/269 (1.9%) |
| Traits with direct `$this->context->` access | 6 traits, 60+ sites |
| ScopeContext (dead code) | 7 lines, empty class |

---

## Issue 1: Entity classes — all-public mutable fields

**Severity**: High. Every entity class exposes all internal state as public writable properties. External code in Preprocessor/CompilerBase directly mutates them.

### 1.1 ClassDef (18 public properties)

`src/Php/Entity/ClassDef.php`

```php
public array $methods = [];          // externally populated: $classDef->properties[$name] = ...
public array $properties = [];       // externally populated
public array $constants = [];        // externally populated
public array $implements = [];       // externally populated
public string $extends = '';         // externally set: $this->classDef->extends = ...
public bool $requireCtor = false;
public bool $enum = false;
public ?string $enumBackingType = null;
public array $enumCases = [];
public array $abstractMethods = [];
public ?Trait_ $trait = null;
public array $traitAliases = [];
public array $traitIgnored = [];
public int $flags;                   // no visibility checks, raw bitmask
public bool $inheritedFromInternalClass = false;
public string $ctorInit = '';        // mutated during code generation
public string $ctorClean = '';       // mutated during code generation
public FunctionContext $propertyContext; // set after construction
```

**Issues**:
- `$properties`, `$methods`, `$constants` — exposed as raw arrays. External code does `$classDef->properties[$name] = $propDef`. No validation that the key matches `$propDef->name`, no type enforcement.
- `$flags` — raw int, no guarantee it's a valid Modifiers bitmask.
- `$ctorInit` / `$ctorClean` — mutated by CompilerBase during code generation, not initialization.
- Property additions use `addMethod()`, `addAbstractMethod()` but array properties are also set directly via `[] =`.
- `$extends` — set directly as raw string, bypasses `parent::__construct()` which also sets it on ClassLikeDef.

**Recommendation**:
- Make `$methods`, `$properties`, `$constants` private, expose via `addMethod()`/`getMethod()` (already exists)
- Make `$flags` private, expose `isAbstract()` (already exists), add `isFinal()`, `isReadonly()`
- Make `$extends` write-once via `setExtends(string)` with validation
- Add `appendCtorInit(string)` and `appendCtorClean(string)` methods instead of direct string mutation

### 1.2 FunctionDef (12 public properties)

`src/Php/Entity/FunctionDef.php`

```php
public string $name;
public string $returnType;
public array $argInfoList = [];       // externally populated: $functionDef->argInfoList[] = $argInfo
public int $argCountRequired = 0;
public string $params = '';          // generated C++ param string, mutated during compilation
public string $namespace;
public bool $method = false;
public bool $stub = false;
public bool $returnTypeUndeclared = false;
public string $returnClass = '';
public ?array $returnTypeCheck = null;
public string $returnTypeStr = '';
public ?NodeAbstract $returnTypeNode = null;
```

**Issues**:
- `$name` and `$namespace` are set in constructor but still publicly writable — should be readonly
- `$argInfoList[]` is directly appended to by Preprocessor (line 329)
- `$params` is a codegen artifact stored on the entity — belongs in a separate compilation context

**Recommendation**:
- Make constructor-set properties readonly (`$name`, `$namespace`, `$returnType`)
- Add `addArg(ArgInfo $arg)` method instead of direct array mutation
- Extract `$params` to a compilation context separate from the definition entity

### 1.3 PropertyDef (7 public properties)

`src/Php/Entity/PropertyDef.php`

```php
public string $name;
public string $type;
public int $flags;
public ?string $default = null;
public ?ArrayInitPlan $arrayInitPlan = null;
public bool $nullable = false;
public string $class = '';           // set after construction
```

**Issues**:
- `$class` is set after construction externally (`$propDef->class = $fullClassName`)
- `$flags` is raw int — already has `isPrivate()`/`isProtected()`/`isPublic()`/`isStatic()` methods, good
- Constructor already sets all core fields — `$class` should be added to the constructor

**Recommendation**:
- Add `$class` to the constructor (it's always known at construction time)
- Make constructor-set fields readonly or private

### 1.4 MethodDef (4 public properties)

`src/Php/Entity/MethodDef.php`

```php
public int $flags;
public string $name;
public ?FunctionDef $functionDef = null;  // set after construction
public bool $hasDynamicCall = false;
```

**Issues**:
- No flag-check methods — inline checks in CompilerBase should use `$methodDef->isPrivate()` instead
- `$functionDef` is set externally: `$this->methodDef->functionDef = $functionDef` (Preprocessor line 411)

**Recommendation**:
- Add `HasFlags` trait (from Phase 3.1 of reuse plan)
- Add `setFunctionDef(FunctionDef $fd)` method with validation

### 1.5 ConstantDef (8 public properties)

```php
public string $name;
public string $type;
public int $flags;
public string $value;
public string $arrayExpr = '';
public string $class = '';
public ?NodeAbstract $valueExpr = null;
```

**Issues**: Same pattern — constructor sets core fields, but `$class` is set externally afterward.

**Recommendation**: Add `$class` to constructor.

---

## Issue 2: FunctionContext — public mutable grab-bag

**Severity**: High. 26 public properties, all writable by any code with access to the context object.

`src/Php/Context/FunctionContext.php`

```php
public ?SsaBuilder $ssaBuilder = null;  // transient analysis state
public array $stableObjects = [];       // SSA optimizer state
public array $hoistedProps = [];        // SSA optimizer state
public array $unsafeObjectProps = [];   // SSA optimizer state
public array $objects = [];             // object variable tracking
public array $stdArrays = [];           // std container tracking
public array $stdContainers = [];       // std container tracking
public array $localVars = [];           // local variable table
public array $staticVars = [];          // static variable table
public array $globalVars = [];          // global variable table
public array $ceWrappers = [];          // class entry wrappers
public int $tmpVarIndex = 0;           // auto-increment counter
public array $arguments = [];           // function arguments
public bool $inLoop = false;           // control-flow state
public bool $inClosure = false;        // control-flow state
public bool $hasMultiLevelBreak = false;
public bool $hasMultiLevelContinue = false;
public bool $inAssignExpr = false;     // expression context
public array $beforeStmtLines = [];     // deferred code (flushed before stmts)
public array $afterStmtLines = [];      // deferred code (flushed after stmts)
public array $objectProps;              // (uninitialized!)
public array $staticPropRefs = [];      // static property references
public int $scopeLevel = 0;            // lexical scope depth
/** @var array<int, ScopeContext> */
public array $scopeLayouts = [];        // per-scope data
```

**Issues**:
- Traits directly mutate deeply nested state: `$this->context->stdArrays[$var] = ...`, `$this->context->localVars[$name] = ...`
- No semantic grouping — analysis state, variable tracking, control-flow flags all mixed
- `$objectProps` is declared but never initialized (could be null at runtime)
- `$scopeLayouts` is managed through `enterScope()`/`leaveScope()` — but can be bypassed
- `$tmpVarIndex` auto-increment — should use a method instead of direct `++`

**Recommendation**:
- Group related properties into sub-objects: `VariableTable`, `ControlFlowState`, `ScopeManager`
- Make properties that should only be read by the compiler layer private/protected with getters
- Add `incrementTmpVar(): int`, `addLocalVar()`, `addBeforeStmt()` methods
- Initialize `$objectProps = []`

---

## Issue 3: CompilerBase — only 1.9% private methods

**Severity**: Medium. Virtually everything is public or protected.

`src/Php/CompilerBase.php` — 269 methods total:
- ~25 public methods (many should be protected or internal)
- ~239 protected methods (most should be private — internal helpers)
- **5 private methods** (1.9%)

### 3.1 Methods that should be private

The following methods are internal helpers only called from within CompilerBase (not from Preprocessor, Translator, or traits). They are unnecessarily `protected`:

| Method | Line | Called from |
|--------|------|-------------|
| `resetFunction()` | 833 | Internal only |
| `resetMethod()` | 840 | Internal only |
| `resetClass()` | 846 | Internal only |
| `resolveObjectClassDef()` | 816 | Already private ✓ |
| `getBigIntLiteralString()` | 1183 | Already private ✓ |
| `getDecimalLiteralString()` | 1188 | Already private ✓ |
| `parseBeforeStmtLines()` | 1321 | Internal, but accessed by traits |
| `parseAfterStmtLines()` | 1331 | Internal, but accessed by traits |
| `genTmpVarName()` | 734 | Public — should at least be protected |

### 3.2 Public methods that are internal concern

| Method | Current visibility | Issue |
|--------|-------------------|-------|
| `genTmpVarName()` | public | Only used internally for variable name generation |
| `writeFile()` | public | File I/O — should be a separate service |
| `stop()` | public | Error helper — could be internal |
| `isScalarInt()` | public | AST helper, only used internally |
| `getType()` | public | AST helper, only used internally |
| `getObjectType()` | public | Type mapping, used internally |
| `getTypeFromZendType()` | public | Type mapping, used internally |
| `getIncludeDir()` | public | Config getter — should be on a Config object |
| `getBuildDir()` | public | Config getter — should be on a Config object |

### 3.3 Public constants leaked as API

30 public constants for internal type names, literal values, etc. These are needed by traits but expose internal naming conventions.

---

## Issue 4: Trait → Context coupling

**Severity**: Medium. Traits bypass any encapsulation boundary and directly mutate `$this->context`.

| Trait | `$this->context->` accesses |
|-------|---------------------------|
| `StdContainerTrait` | 40+ accesses to `stdArrays`, `stdContainers`, `objects`, `localVars` |
| `LoopVarOptimizer` | accesses to `localVars`, `arguments`, `scopeLevel`, `inLoop` |
| `SsaPropOptimizer` | accesses to `stableObjects`, `hoistedProps`, `unsafeObjectProps`, `objects` |
| `FuncCallOptimizer` | accesses to `beforeStmtLines`, `arguments`, `localVars` |
| `SsaTypeOptimizer` | accesses to `localVars` |
| `BinaryOpTrait` | accesses to `objects`, `localVars` |

**Issues**:
- Traits have no declared contract — they assume `$this->context` exists and has specific properties
- If a property name changes in FunctionContext, all 6 traits break silently
- No type safety — arrays are indexed by string but accessed with arbitrary keys

**Recommendation**:
- Define a `ContextAccess` interface that traits must use instead of direct property access
- Or: inject context into trait methods as a parameter instead of reading from `$this`
- Short-term: add `@property-read` annotations to document the contract

---

## Issue 5: Preprocessor directly mutates entity state

**Severity**: Medium. Preprocessor bypasses entity boundaries.

`src/Php/Preprocessor.php`:
```php
line 279: $argInfo->name = $name;                          // direct property set
line 329: $functionDef->argInfoList[] = $argInfo;           // direct array append
line 411: $this->methodDef->functionDef = $functionDef;     // direct property set
line 447: $this->classDef->extends = $this->parentClass;    // direct property set
line 585: $this->classDef->constants[$constInfo->name] = ...; // direct array set
line 618: $this->classDef->properties[$name] = $propDef;    // direct array set
```

**Recommendation**: Use entity methods: `$functionDef->addArg($argInfo)`, `$this->methodDef->setFunctionDef($functionDef)`, `$classDef->addProperty($propDef)`, etc.

---

## Issue 6: CompilerBase protected state leaked to inheritance chain

**Severity**: Low-Medium. The chain CompilerBase → Preprocessor → Translator means any protected property in CompilerBase is accessible from Translator.

CompilerBase has ~50 protected properties. Translator is 3301 lines and accesses many of them. There's no way to know which properties are "safe to use" vs "internal to CompilerBase."

**Recommendation**: Migrate internal-only properties to `private` over time, with explicit getter methods where needed.

---

## Issue 7: Platform/Backend — well encapsulated

**Severity**: None. The Platform and Backend layers are well-encapsulated:
- All state is private (e.g., `$compilerCommand`, `$linkerCommand` in GccLikeBackend)
- Only methods are public
- Abstract contracts are clear
- Factory pattern is used consistently

**No changes needed in this layer.**

---

## Issue 8: ScopeContext is dead code

**Severity**: Low. `src/Php/Context/ScopeContext.php` — 7 lines, empty class body. Used as a placeholder type in FunctionContext's `$scopeLayouts` array. Either populate it or remove it.

---

## Implementation Priority

| # | Issue | Impact | Effort | Risk |
|---|-------|--------|--------|------|
| 1.1 | Entity: readonly for constructor fields | Data integrity | 2h | Low |
| 1.2 | Entity: add mutation methods (addArg, addProperty, etc.) | Safe mutation | 3h | Medium |
| 2.1 | FunctionContext: group properties into sub-objects | Clarity | 4h | Medium |
| 2.2 | FunctionContext: add accessor methods | Controlled mutation | 3h | Medium |
| 3 | CompilerBase: demote public→protected, protected→private | Boundary clarity | 4h | Medium |
| 4 | Define trait context contract | Safe coupling | 3h | Medium |
| 5 | Preprocessor: use entity methods | Consistent mutation | 2h | Low |
| 8 | Remove ScopeContext dead code | Cleanup | 15m | None |

**Recommended order**: Start with 8 (quick win), then 1.1 + 1.2 (entity cleanup), then 2.1 + 2.2 (context cleanup), then 3 + 5 + 4 (CompilerBase boundary).
