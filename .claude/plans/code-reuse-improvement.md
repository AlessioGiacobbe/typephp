# Code Reuse Improvement Plan

## Analysis Summary

| Metric | Value |
|--------|-------|
| Total source lines | ~11,000 (PHP only) |
| CompilerBase | 5,917 lines, 269 methods, 20 traits |
| Gcc↔Clang duplication | ~70-80% of methods |
| Linux↔Macos duplication | ~80% of methods |
| `fatalError()` call sites | 174+ across codebase |
| Test setUp/tearDown dup | 4+ test classes |

---

## Phase 1: High-Impact Backend/Platform Deduplication (P0)

### 1.1 Extract `UnixPlatform` base class

**Files**: `Platform/Linux.php` (249 lines), `Platform/Macos.php` (278 lines)

These 13 methods are 100% identical between Linux and Macos:
- `getIncludeFlags()`, `getLibraryPathFlags()`, `getObjectExtension()`, `getExecutableExtension()`, `getPathSeparator()`, `getPhpDir()`, `getRpathOptions()`, `getPicFlag()`, `buildPhpIncludePaths()`, `findPhpConfig()`, `buildPhpLibPaths()`

Near-identical with minor parameterization:
- `getLibraryFlags()` — only the regex differs (`.a|.so` vs `.a|.dylib`)
- `detectPhpLibs()` — only the lib name differs (`libphp.so` vs `libphp.dylib`)

**Plan**: Create `UnixPlatform extends PlatformBase` between `PlatformBase` and `Linux`/`Macos`. Move all identical methods up. Add abstract `getSharedLibraryExtension()` (already exists) and a protected `getSharedLibName()` for the single differing method.

**Expected savings**: ~180 lines removed, ~150 lines added = net ~30 lines but massive maintainability gain.

### 1.2 Extract `GccLikeBackend` base class

**Files**: `Backend/Gcc.php` (379 lines), `Backend/Clang.php` (515 lines)

These methods are structurally identical with only Windows-specific branching:
- `compileFile()`, `linkObjects()`, `buildCompileCommand()`, `buildCCompileCommand()`, `buildNativeCompileCommand()`, `buildLinkCommand()`, `buildCompileOptions()`, `buildLinkOptions()`, `buildFullCompileOptions()`, `buildFullLinkOptions()`

**Plan**: Create `GccLikeBackend extends CompilerBackend` with all shared logic. Define template-method hooks for the differences:
- `getCompilerSpecificFlags()` — empty for Gcc, MSVC compat flags for Clang/Windows
- `getOutputFlag($isWindows)` — `-o` vs `/OUT:`
- `getSanitizerFlag($type)` — handle the `address`/`addr` aliasing difference
- `getPICHandling($config)` — Gcc always adds `-fPIC`, Clang skips on Windows

**Expected savings**: ~250+ lines removed from Gcc.php and Clang.php. Msvc.php is sufficiently different (different flag syntax) to remain standalone.

---

## Phase 2: CompilerBase Internal Deduplication (P1)

### 2.1 Consolidate Big* type dispatch in BinaryOpTrait

**File**: `Parser/BinaryOpTrait.php` (lines 31-107)

The three blocks for BigFloat (lines 31-54), Decimal (lines 56-76), and BigInt (lines 78-107) in `parseBinaryOp()` share identical structure:
1. Check if either operand is the big type
2. Guard against incompatible mixing
3. Convert the non-matching operand
4. Dispatch to arithmetic or comparison operator

**Plan**: Extract `parseBigNumBinaryOp(string $type, string $left, string $right, ...)` parameterized by type name, conversion function, and operator maps. Same refactoring applies to `genBigNumericCmp()` (lines 315-355).

**Expected savings**: ~40 lines.

### 2.2 Data-driven operator dispatch tables

**Files**: `Parser/BinaryOpTrait.php` (16 wrapper methods), `Parser/AssignOpTrait.php` (14 wrapper methods)

30+ thin methods that are just `parseBinaryOp($left, $right, '+')` / `parseAssignOp($node, '+=')`.

**Plan**: Replace with a static map in `parseExpr()`:
```php
private const BINARY_OP_MAP = [
    'Expr_BinaryOp_Plus' => '+',
    'Expr_BinaryOp_Minus' => '-',
    // ...
];
private const ASSIGN_OP_MAP = [
    'Expr_AssignOp_Plus' => '+=',
    // ...
];
```

**Expected savings**: ~200 lines removed (boilerplate method bodies).

### 2.3 Deduplicate call dispatch patterns

**Files**: `CompilerBase.php` (`parseFuncCall`, `parseMethodCall`, `parseStaticCall` — ~300 lines combined), `UniversalMethodCall.php` (`tryOptimizePhpFn` vs `dispatchFuncCall`)

These share the same overall flow: resolve callable → try native/optimized path → on `PlaceHolder` fall back to placeholder → parse args → wrap in `php::call()`. Additionally, `tryOptimizePhpFn()` (UniversalMethodCall lines 720-772) duplicates the argument type conversion logic already present in `dispatchFuncCall()` (FuncCallOptimizer lines 234-269).

**Plan**: Extract a shared `resolveCall(CallLike $expr, ...)` method. Unify arg conversion so `tryOptimizePhpFn` delegates to `dispatchFuncCall` instead of reimplementing it.

**Expected savings**: ~40 lines, fixes double-calculation of arg conversions.

### 2.4 Deduplicate UNIVERSAL_METHODS math entries

**File**: `UniversalMethodCall.php` (lines 12-69)

The INT block (lines 12-41) and FLOAT block (lines 42-69) contain 20 identical math method entries (`abs`, `ceil`, `floor`, `sqrt`, `sin`, `cos`, etc.) differing only in `return_type`. Also the `calc_op` entries (add/sub/mul/div) are duplicated.

**Plan**: Define math method names once in a shared array, generate both INT and FLOAT entries in the constructor with the appropriate `return_type`.

**Expected savings**: ~25 lines of config data.

### 2.5 Deduplicate constant folding methods

**File**: `Optimizer/FuncCallOptimizer.php` (lines 515-593)

8 methods (`doFoldStringLen`, `doFoldStringCase`, `doFoldCmp2`, `doFoldCmp3`, `doFoldCountLiteral`, `doFoldKnownClass`, `doFoldKnownConstant`, `doFoldSsaType`) follow the identical pattern: extract args → check types → compute → return literal or false.

**Plan**: Create a generic `tryFold(callable $check, callable $compute)` that handles the arg extraction and short-circuit boilerplate. Each folder becomes a one-liner.

**Expected savings**: ~50 lines.

### 2.6 Remove MSVC compat flag duplication in Clang

**File**: `Backend/Clang.php`

The 4-line MSVC compatibility block (`-fms-compatibility`, `-fms-compatibility-version=19.40`, `-fdelayed-template-parsing`, `-fms-extensions`) appears 7 times (compileFile, buildCompileCommand, buildCCompileCommand, buildNativeCompileCommand, buildFullCompileOptions, buildCompileOptions, buildLinkOptions).

**Plan**: Extract `private function getMsvcCompatFlags(): string` method. Called once per method that needs it instead of repeated inline.

**Expected savings**: ~24 lines, single point of change if MSVC compat flags need updating.

### 2.7 Merge return-check blocks

**File**: `CompilerBase.php`, `parseReturn()` (line 1621) and `genReturnCode()` (line 5741)

Identical 7-line union type check blocks.

**Plan**: Extract `genUnionReturnWrapper(string $exprVar)` method.

**Expected savings**: ~10 lines, eliminates drift risk.

### 2.8 Fix `buildCCompileCommand()` inconsistency between Gcc and Clang

**Files**: `Backend/Gcc.php` (lines 131-137), `Backend/Clang.php` (lines 198-206)

Gcc unconditionally appends `-O$level` then conditionally appends `-g`. Clang treats debug and optimization as mutually exclusive (`if debug: -O0 -g` else `-O$level`). This is a behavioral inconsistency between backends implementing the same abstract method.

**Plan**: Standardize on one behavior (the Clang pattern of `-O0 -g` for debug is the correct one — debug builds should not optimize). This will be automatically resolved by Phase 1.2 (GccLikeBackend).

---

## Phase 3: Structural Improvements (P2)

### 3.1 Entity flag-check consistency

**File**: `Entity/PropertyDef.php` has `isPrivate()`, `isProtected()`, `isPublic()`, `isStatic()`. `Entity/MethodDef.php` has none — flag checks are done inline in CompilerBase.

**Plan**: Add a `HasFlags` trait used by both `PropertyDef` and `MethodDef`:
```php
trait HasFlags {
    public function isPrivate(): bool { return $this->flags & Modifiers::PRIVATE; }
    public function isProtected(): bool { return $this->flags & Modifiers::PROTECTED; }
    public function isPublic(): bool { return !$this->isPrivate() && !$this->isProtected(); }
    public function isStatic(): bool { return $this->flags & Modifiers::STATIC; }
    public function isAbstract(): bool { return $this->flags & Modifiers::ABSTRACT; }
}
```

**Expected savings**: Removes inline flag checks from CompilerBase, adds clarity.

### 3.2 Test infrastructure base class

**Files**: `phpunit/src/AstNodeTypeTest.php`, `CompilerBaseAdapterTest.php`, `TraitsTest.php`, `PreprocessorTest.php`

All 4 duplicate the same setUp/tearDown pattern: create temp dir, `CompilerTest::create()`, recursive cleanup.

**Plan**: Add `CompilerTestCase extends \PHPUnit\Framework\TestCase` to `phpunit/bootstrap.php`:
```php
abstract class CompilerTestCase extends TestCase {
    protected string $tmpDir;
    protected CompilerTest $compiler;

    protected function setUp(): void {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/compiler_test_' . uniqid();
        mkdir($this->tmpDir, 0777, true);
        $this->compiler = CompilerTest::create($this->tmpDir);
    }

    protected function tearDown(): void {
        parent::tearDown();
        // recursive cleanup
    }
}
```

### 3.3 Eliminate `buildFull*Options` / `build*Options` duality

**Files**: `Backend/Gcc.php`, `Backend/Clang.php`, `Backend/Msvc.php`

All three backends implement both `buildFullCompileOptions()` / `buildCompileOptions()` and `buildFullLinkOptions()` / `buildLinkOptions()`. The "full" variants are subsets of the "standard" variants working from differently-keyed option arrays. They have drifted independently (e.g., RPATH handling differs between the two in Gcc/Clang).

**Plan**: Make the "full" variants delegate to the "standard" variants by normalizing their option keys once at the call site. Keep only one code path for each (compile/link).

**Expected savings**: ~100+ lines, eliminates drift between the two variants.

### 3.4 Deduplicate Preprocessor AST switch

**File**: `Preprocessor.php`

`prepareFile()` (lines 115-151) and `prepareNamespace()` (lines 196-223) both switch over the same set of AST `Stmt_*` types with nearly identical case bodies.

**Plan**: Extract `processStmt(Node $v)` method that both callers share.

**Expected savings**: ~25 lines.

### 3.5 Remove dead code: `ScopeContext`

**File**: `Context/ScopeContext.php` (7 lines)

An empty class with no properties or methods. Used only as a type annotation in `FunctionContext`. Either populate it with scope-relevant state, or remove it and use plain `\stdClass` / array / null.

### 3.6 Reduce StdContainerTrait coupling

**File**: `Parser/StdContainerTrait.php` (823 lines, 48 methods)

This is effectively a standalone subsystem for std container handling. As a trait, it has unrestricted access to CompilerBase's internals.

**Plan**: Extract core logic into `StdContainerHandler` service class. The trait becomes a thin facade that delegates to the handler.

**Expected savings**: Better testability, clearer boundaries, easier to understand.

---

## Phase 4: Longer-Term Architectural (P3)

### 4.1 Break CompilerBase into domain-specific classes

Currently CompilerBase is a 5,917-line god class using 20 traits as a workaround for PHP's single inheritance. Consider:

- `ExpressionCompiler` — all parseExpr sub-dispatch (~500 lines)
- `StatementCompiler` — parseStmts, parseIf, parseWhile, parseFor, parseSwitch, etc.
- `TypeResolver` — parseTypeDecl, detectClassOfExpr, type checking
- `CallResolver` — parseFuncCall, parseMethodCall, parseStaticCall, parseNew

These would be injected services rather than traits, making CompilerBase a coordinator.

### 4.2 Shared AST walker pattern with Python Translator

Both PHP and Python translators implement the same "walk-collect-indent-emit" pipeline independently. `Core\Translator` could define a standard `walkAst($nodes, callable $visitor)` that handles indentation and line collection.

---

## Implementation Order & Impact Matrix

| # | Item | Savings | Risk | Effort |
|---|------|---------|------|--------|
| 1.1 | UnixPlatform base class | ~180 dup lines | Low | 2-3h |
| 1.2 | GccLikeBackend base class | ~250 dup lines | Medium | 3-4h |
| 2.1 | BigNum dispatch consolidation | ~40 lines | Low | 1h |
| 2.2 | Data-driven op dispatch | ~200 lines | Low | 1-2h |
| 2.3 | Unify call dispatch patterns | ~40 lines | Low | 1-2h |
| 2.4 | UNIVERSAL_METHODS math dedup | ~25 lines | Low | 30m |
| 2.5 | Fold method template | ~50 lines | Low | 1h |
| 2.6 | MSVC compat flags in Clang | ~24 lines | Low | 30m |
| 2.7 | Merge return-check blocks | ~10 lines | Low | 30m |
| 2.8 | Fix buildCCompileCommand drift | bug fix | Low | 30m |
| 3.1 | HasFlags trait | clarity | Low | 1h |
| 3.2 | CompilerTestCase base class | boilerplate | Low | 1h |
| 3.3 | Eliminate Full*Options duality | ~100 lines | Medium | 2h |
| 3.4 | Preprocessor AST switch dedup | ~25 lines | Low | 1h |
| 3.5 | Remove dead ScopeContext | 7 lines | Low | 15m |
| 3.6 | StdContainer service class | boundary | Medium | 3-4h |
| 4.1 | Domain classes | architecture | High | 1-2 weeks |
| 4.2 | AST walker pattern | architecture | Medium | 3-5h |

**Total estimated savings**: ~950+ lines of duplicated / dead code.

**Recommended execution**: Phase 1 → Phase 2 → Phase 3. Items within each phase are independent and can be parallelized.
