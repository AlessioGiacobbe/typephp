<?php

namespace TypePhp\Python;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;
use TypePhp\Type;

trait PythonModuleTrait
{
    /** @var array<string, string> TypePHP constructor sugar to the existing phpy facade class. */
    private const PYTHON_CONSTRUCTOR_CLASSES = [
        'list' => 'PyList',
        'dict' => 'PyDict',
        'tuple' => 'PyTuple',
        'set' => 'PySet',
        'str' => 'PyStr',
        'object' => 'PyObject',
    ];

    /** @var array<string, string> Python calls whose precise phpy wrapper is known statically. */
    private const PYTHON_BUILTIN_RETURN_CLASSES = [
        ...self::PYTHON_CONSTRUCTOR_CLASSES,
        'int' => 'PyObject',
        'float' => 'PyObject',
        'bytes' => 'PyObject',
        'type' => 'PyType',
    ];

    /** @var array<string, true> Builtins exposed as explicit PyCore methods. */
    private const PYTHON_CORE_FUNCTIONS = [
        'int' => true,
        'float' => true,
        'bytes' => true,
        'scalar' => true,
    ];

    /** @var array<string, string> Lowercase alias to case-sensitive Python module name. */
    protected array $pythonModuleAliases = [];

    /** @var array<string, int> Case-sensitive Python module name to generated slot ID. */
    protected array $pythonModuleMap = [];

    protected int $pythonModuleIndex = 0;

    protected bool $pythonRuntimeUsed = false;

    /** Return true when the expression is statically known to hold a phpy proxy. */
    protected function isPythonObjectExpr(NodeAbstract $expr): bool
    {
        $class = $this->detectClassOfExpr($expr);
        if ($class === '') {
            return false;
        }
        return strcasecmp($class, 'PyObject') === 0
            || $this->isObjectClassStaticallyAssignableTo($class, 'PyObject');
    }

    /**
     * Python members are resolved by the Python VM. Only methods explicitly
     * registered on the phpy wrapper may use a cached Zend method pointer;
     * every other name must reach PyObject::__call().
     */
    protected function isPythonDynamicMethodCall(NodeAbstract $receiver, string $method): bool
    {
        if (!$this->isPythonObjectExpr($receiver)) {
            return false;
        }

        $class = $this->detectClassOfExpr($receiver);
        return $class === '' || !\TypePhp\Resolver\Reflection::hasMethod($class, $method);
    }

    protected function getPythonBinaryOperator(Expr\BinaryOp $expr): ?string
    {
        return match (true) {
            $expr instanceof Expr\BinaryOp\Plus => 'add',
            $expr instanceof Expr\BinaryOp\Minus => 'sub',
            $expr instanceof Expr\BinaryOp\Mul => 'mul',
            $expr instanceof Expr\BinaryOp\Div => 'truediv',
            $expr instanceof Expr\BinaryOp\Mod => 'mod',
            $expr instanceof Expr\BinaryOp\Pow => 'pow',
            $expr instanceof Expr\BinaryOp\ShiftLeft => 'lshift',
            $expr instanceof Expr\BinaryOp\ShiftRight => 'rshift',
            $expr instanceof Expr\BinaryOp\BitwiseAnd => 'and_',
            $expr instanceof Expr\BinaryOp\BitwiseOr => 'or_',
            $expr instanceof Expr\BinaryOp\BitwiseXor => 'xor',
            $expr instanceof Expr\BinaryOp\Equal => 'eq',
            $expr instanceof Expr\BinaryOp\NotEqual => 'ne',
            $expr instanceof Expr\BinaryOp\Smaller => 'lt',
            $expr instanceof Expr\BinaryOp\SmallerOrEqual => 'le',
            $expr instanceof Expr\BinaryOp\Greater => 'gt',
            $expr instanceof Expr\BinaryOp\GreaterOrEqual => 'ge',
            $expr instanceof Expr\BinaryOp\Identical => 'is_',
            $expr instanceof Expr\BinaryOp\NotIdentical => 'is_not',
            default => null,
        };
    }

    protected function isPythonBinaryOperatorExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\BinaryOp
            && $this->getPythonBinaryOperator($expr) !== null
            && ($this->isPythonObjectExpr($expr->left) || $this->isPythonObjectExpr($expr->right));
    }

    protected function pythonOperatorReturnsBool(Expr\BinaryOp $expr): bool
    {
        return $expr instanceof Expr\BinaryOp\Equal
            || $expr instanceof Expr\BinaryOp\NotEqual
            || $expr instanceof Expr\BinaryOp\Smaller
            || $expr instanceof Expr\BinaryOp\SmallerOrEqual
            || $expr instanceof Expr\BinaryOp\Greater
            || $expr instanceof Expr\BinaryOp\GreaterOrEqual
            || $expr instanceof Expr\BinaryOp\Identical
            || $expr instanceof Expr\BinaryOp\NotIdentical;
    }

    /**
     * Lower a Python operation through the standard operator module.
     * Braced ArgList elements are sequenced by C++17; call operands that can
     * emit statements are materialized first by parseOrderedOperand().
     */
    protected function parsePythonBinaryOperator(Expr\BinaryOp $expr): ?string
    {
        if (!$this->isPythonBinaryOperatorExpr($expr)) {
            return null;
        }

        $left = $this->parseOrderedOperand($expr->left, false);
        $right = $this->parseOrderedOperand($expr->right, false);
        $call = $this->getPythonModuleExpression('operator')
            . '.call(' . $this->getLiteralString($this->getPythonBinaryOperator($expr))
            . ', php::ArgList{' . $left . ', ' . $right . '})';

        return $this->pythonOperatorReturnsBool($expr)
            ? $this->convertPythonResultToBool($call)
            : $call;
    }

    protected function convertPythonResultToBool(string $expression): string
    {
        $this->markPythonRuntimeUsed();
        $scalar = 'php::call(' . $this->getClassEntryPtr('PyCore') . ', '
            . $this->getFuncPtr('PyCore::scalar') . ', php::ArgList{' . $expression . '})';
        return 'php::toBool(' . $scalar . ')';
    }

    protected function detectPythonOperatorReturnType(NodeAbstract $expr): ?string
    {
        if ($this->isPythonBinaryOperatorExpr($expr)) {
            return $this->pythonOperatorReturnsBool($expr) ? Type::BOOL : Type::OBJECT;
        }
        if ($this->isPythonUnaryOperatorExpr($expr)) {
            return $expr instanceof Expr\BooleanNot ? Type::BOOL : Type::OBJECT;
        }
        return null;
    }

    protected function detectPythonOperatorReturnClass(NodeAbstract $expr): ?string
    {
        if ($this->isPythonBinaryOperatorExpr($expr)) {
            return $this->pythonOperatorReturnsBool($expr) ? null : 'PyObject';
        }
        if ($this->isPythonUnaryOperatorExpr($expr)) {
            return $expr instanceof Expr\BooleanNot ? null : 'PyObject';
        }
        return null;
    }

    protected function getPythonUnaryOperator(NodeAbstract $expr): ?string
    {
        return match (true) {
            $expr instanceof Expr\UnaryMinus => 'neg',
            $expr instanceof Expr\UnaryPlus => 'pos',
            $expr instanceof Expr\BitwiseNot => 'invert',
            $expr instanceof Expr\BooleanNot => 'not_',
            default => null,
        };
    }

    protected function isPythonUnaryOperatorExpr(NodeAbstract $expr): bool
    {
        return $this->getPythonUnaryOperator($expr) !== null
            && $this->isPythonObjectExpr($expr->expr);
    }

    protected function parsePythonUnaryOperator(NodeAbstract $expr): ?string
    {
        if (!$this->isPythonUnaryOperatorExpr($expr)) {
            return null;
        }

        $operand = $this->parseOrderedOperand($expr->expr, false);
        $call = $this->getPythonModuleExpression('operator')
            . '.call(' . $this->getLiteralString($this->getPythonUnaryOperator($expr))
            . ', php::ArgList{' . $operand . '})';
        return $expr instanceof Expr\BooleanNot ? $this->convertPythonResultToBool($call) : $call;
    }

    protected function convertPythonObjectToBool(NodeAbstract $expr, string $parsed): ?string
    {
        if (!$this->isPythonObjectExpr($expr)) {
            return null;
        }
        $call = $this->getPythonModuleExpression('operator')
            . '.call(' . $this->getLiteralString('truth') . ', php::ArgList{' . $parsed . '})';
        return $this->convertPythonResultToBool($call);
    }

    protected function getPythonAssignOperator(Expr\AssignOp $expr): ?string
    {
        return match (true) {
            $expr instanceof Expr\AssignOp\Plus => 'iadd',
            $expr instanceof Expr\AssignOp\Minus => 'isub',
            $expr instanceof Expr\AssignOp\Mul => 'imul',
            $expr instanceof Expr\AssignOp\Div => 'itruediv',
            $expr instanceof Expr\AssignOp\Mod => 'imod',
            $expr instanceof Expr\AssignOp\Pow => 'ipow',
            $expr instanceof Expr\AssignOp\ShiftLeft => 'ilshift',
            $expr instanceof Expr\AssignOp\ShiftRight => 'irshift',
            $expr instanceof Expr\AssignOp\BitwiseAnd => 'iand',
            $expr instanceof Expr\AssignOp\BitwiseOr => 'ior',
            $expr instanceof Expr\AssignOp\BitwiseXor => 'ixor',
            default => null,
        };
    }

    /**
     * Lower Python compound assignments through operator.i*(). The target
     * receiver and key are materialized before the RHS, then the returned
     * Python object is written back through the original PHP lvalue protocol.
     */
    protected function parsePythonAssignOperator(Expr\AssignOp $expr): ?string
    {
        $method = $this->getPythonAssignOperator($expr);
        if ($method === null || !$this->isPythonObjectExpr($expr->var)) {
            return null;
        }

        $writeBack = null;
        if ($this->isVarExpr($expr->var)) {
            $left = $this->parseWritableIdentifier($expr->var);
            $writeBack = static fn(string $value): string => $left . ' = ' . $value;
        } elseif ($expr->var instanceof Expr\ArrayDimFetch) {
            if ($expr->var->dim === null) {
                $this->fatalError($expr->var, 'Cannot use [] for a Python compound assignment');
            }
            $container = $this->parseOrderedOperand($expr->var->var, false);
            $key = $this->parseOrderedOperand($expr->var->dim, false);
            $left = $container . '.item(' . $key . ', false)';
            $writeBack = static fn(string $value): string => $container . '.offsetSet(' . $key . ', ' . $value . ')';
        } elseif ($expr->var instanceof Expr\PropertyFetch && $this->isIdExpr($expr->var->name)) {
            $receiver = $this->parseOrderedOperand($expr->var->var, false);
            $property = $this->identifierToStr($expr->var->name, literal: true);
            $left = $receiver . '.attr(' . $property . ', php::AttrMode::Get)';
            $writeBack = static fn(string $value): string => 'typephp_write_property_scoped('
                . $receiver . ', ' . $property . ', ' . $value . ', nullptr)';
        } else {
            return null;
        }

        $right = $this->parseOrderedOperand($expr->expr, false);
        $call = $this->getPythonModuleExpression('operator')
            . '.call(' . $this->getLiteralString($method)
            . ', php::ArgList{' . $left . ', ' . $right . '})';
        if ($this->isVarExpr($expr->var)) {
            return $writeBack($call);
        }

        $result = $this->addTmpVar(Type::OBJECT);
        return '((' . $result . ' = ' . $call . ', ' . $writeBack($result) . '), ' . $result . ')';
    }

    protected function resetPythonModuleAliases(): void
    {
        $this->pythonModuleAliases = [];
    }

    protected function parsePythonUse(Node\UseItem $use, string $name, NodeAbstract $statement): bool
    {
        $parts = explode('\\', trim($name, '\\'));
        if (strcasecmp($parts[0] ?? '', 'python') !== 0) {
            return false;
        }
        if (count($parts) < 2) {
            $this->fatalError($statement, 'The special `python` namespace must be followed by a module name');
        }

        // PHP namespace separators express Python's dotted module path only
        // in source syntax; PyCore::import() expects the canonical Python name.
        $module = implode('.', array_slice($parts, 1));
        $alias = $use->alias?->toString() ?? $parts[array_key_last($parts)];
        $aliasKey = strtolower($alias);

        if (isset($this->pythonModuleAliases[$aliasKey]) && $this->pythonModuleAliases[$aliasKey] !== $module) {
            $this->fatalError($use, "Python module alias `{$alias}` is already used");
        }
        if (isset($this->useAliases[$alias]) || isset($this->useFunctions[$alias]) || isset($this->useConstants[$alias])) {
            $this->fatalError($use, "Python module alias `{$alias}` conflicts with an existing use symbol");
        }

        $this->pythonModuleAliases[$aliasKey] = $module;
        return true;
    }

    protected function hasPythonModuleAlias(string $alias): bool
    {
        return isset($this->pythonModuleAliases[strtolower($alias)]);
    }

    protected function resolvePythonModuleAlias(NodeAbstract $class): ?string
    {
        if (!$this->isNameExpr($class)) {
            return null;
        }
        $alias = $this->parseIdentifier($class);
        if (str_contains($alias, '\\')) {
            return null;
        }
        return $this->pythonModuleAliases[strtolower($alias)] ?? null;
    }

    protected function getPythonModuleId(string $module): int
    {
        if (isset($this->pythonModuleMap[$module])) {
            return $this->pythonModuleMap[$module];
        }

        $id = $this->pythonModuleIndex++;
        $this->pythonModuleMap[$module] = $id;

        $this->markPythonRuntimeUsed();
        $this->getFuncId('PyCore::import');
        $this->getLiteralString('PyCore::import');
        $this->getLiteralString($module);

        return $id;
    }

    protected function markPythonRuntimeUsed(): void
    {
        if ($this->pythonRuntimeUsed) {
            return;
        }
        $this->pythonRuntimeUsed = true;

        // Register runtime Zend symbols during conversion so generated map
        // sizes are final before headers are emitted.
        $this->getClassId('PyCore');
        $this->getFuncId('PyCore::setOptions');
        $this->getLiteralString('PyCore');
        $this->getLiteralString('PyCore::setOptions');
        $this->getLiteralString('return_as_object');
    }

    protected function withPythonRuntimeConfigured(string $expression): string
    {
        return '(' . self::PREFIX . 'configure_python_runtime(), ' . $expression . ')';
    }

    protected function getPythonModuleExpression(string $module): string
    {
        return 'php_get_python_module(' . $this->getPythonModuleId($module) . ', '
            . $this->getLiteralString($module) . ')';
    }

    protected function resolvePythonBuiltinName(NodeAbstract $name): ?string
    {
        if (!$this->isNameExpr($name) && !$this->isFullNameExpr($name)) {
            return null;
        }
        $parts = explode('\\', trim($this->parseIdentifier($name), '\\'));
        if (strcasecmp($parts[0] ?? '', 'python') !== 0) {
            return null;
        }
        if (count($parts) !== 2 || $parts[1] === '') {
            $this->fatalError($name, 'Python builtins must use the form `python\\name()`');
        }
        return $parts[1];
    }

    protected function parsePythonBuiltinCall(Expr\FuncCall $expr): ?string
    {
        $builtin = $this->resolvePythonBuiltinName($expr->name);
        if ($builtin === null) {
            return null;
        }
        if ($expr->isFirstClassCallable()) {
            $this->fatalError($expr, 'Python builtins do not support first-class callable syntax yet');
        }
        $this->markPythonRuntimeUsed();

        // This is a deliberately closed map. In particular, PyDict's PHP-array
        // constructor is not equivalent to Python's dict(iterable) builtin.
        $constructorClass = self::PYTHON_CONSTRUCTOR_CLASSES[$builtin] ?? null;
        if ($constructorClass !== null) {
            $classEntry = $this->getClassEntryPtr($constructorClass);
            if ($expr->args === []) {
                return $this->withPythonRuntimeConfigured('php::newObject(' . $classEntry . ')');
            }
            return $this->withPythonRuntimeConfigured(
                'php::newObject(' . $classEntry . ', '
                . $this->parseCallArgs($expr->args, '__construct', $constructorClass) . ')'
            );
        }

        // Explicit PyCore methods either preserve a Python wrapper by design,
        // or (`scalar`) explicitly leave Python's object-preserving rules.
        if (isset(self::PYTHON_CORE_FUNCTIONS[$builtin])) {
            $callable = $this->getClassEntryPtr('PyCore') . ', '
                . $this->getFuncPtr('PyCore::' . $builtin);
            if ($expr->args === []) {
                return $this->withPythonRuntimeConfigured('php::call(' . $callable . ')');
            }
            return $this->withPythonRuntimeConfigured(
                $this->genRuntimeFunctionCall($callable, $expr->args, $builtin, 'PyCore')
            );
        }

        $target = $this->getPythonModuleExpression('builtins');
        $name = $this->getLiteralString($builtin);
        if ($expr->args === []) {
            return $target . '.call(' . $name . ')';
        }
        return $target . '.call(' . $name . ', ' . $this->parseCallArgs($expr->args) . ')';
    }

    protected function detectPythonExpressionReturnType(NodeAbstract $expr): ?string
    {
        if ($expr instanceof Expr\StaticCall && $this->resolvePythonModuleAlias($expr->class) !== null) {
            return Type::OBJECT;
        }
        if ($expr instanceof Expr\StaticPropertyFetch && $this->resolvePythonModuleAlias($expr->class) !== null) {
            return Type::OBJECT;
        }
        if ($expr instanceof Expr\MethodCall && $this->isPythonObjectExpr($expr->var)) {
            if (!$this->isIdExpr($expr->name)
                || $this->isPythonDynamicMethodCall($expr->var, $this->parseIdentifier($expr->name))
            ) {
                return Type::OBJECT;
            }
            return null;
        }
        if ($expr instanceof Expr\PropertyFetch && $this->isPythonObjectExpr($expr->var)) {
            return Type::OBJECT;
        }
        if ($expr instanceof Expr\ArrayDimFetch && $this->isPythonObjectExpr($expr->var)) {
            return Type::OBJECT;
        }
        if ($expr instanceof Expr\FuncCall
            && $expr->name instanceof NodeAbstract
            && !$this->isNameExpr($expr->name)
            && $this->isPythonObjectExpr($expr->name)
        ) {
            return Type::OBJECT;
        }
        if (!$expr instanceof Expr\FuncCall) {
            return null;
        }
        $builtin = $this->resolvePythonBuiltinName($expr->name);
        if ($builtin === null) {
            return null;
        }
        if ($builtin === 'scalar') {
            return Type::VAR;
        }
        return Type::OBJECT;
    }

    protected function detectPythonExpressionReturnClass(NodeAbstract $expr): ?string
    {
        if ($expr instanceof Expr\StaticCall && $this->resolvePythonModuleAlias($expr->class) !== null) {
            return 'PyObject';
        }
        if ($expr instanceof Expr\StaticPropertyFetch && $this->resolvePythonModuleAlias($expr->class) !== null) {
            return 'PyObject';
        }
        if ($expr instanceof Expr\MethodCall && $this->isPythonObjectExpr($expr->var)) {
            if (!$this->isIdExpr($expr->name)
                || $this->isPythonDynamicMethodCall($expr->var, $this->parseIdentifier($expr->name))
            ) {
                return 'PyObject';
            }
            return null;
        }
        if ($expr instanceof Expr\PropertyFetch && $this->isPythonObjectExpr($expr->var)) {
            return 'PyObject';
        }
        if ($expr instanceof Expr\ArrayDimFetch && $this->isPythonObjectExpr($expr->var)) {
            return 'PyObject';
        }
        if ($expr instanceof Expr\FuncCall
            && $expr->name instanceof NodeAbstract
            && !$this->isNameExpr($expr->name)
            && $this->isPythonObjectExpr($expr->name)
        ) {
            return 'PyObject';
        }
        if (!$expr instanceof Expr\FuncCall) {
            return null;
        }
        $builtin = $this->resolvePythonBuiltinName($expr->name);
        if ($builtin === null) {
            return null;
        }
        if ($builtin === 'scalar') {
            return null;
        }
        return self::PYTHON_BUILTIN_RETURN_CLASSES[$builtin] ?? 'PyObject';
    }

    protected function parsePythonModuleStaticCall(Expr\StaticCall $expr): ?string
    {
        if (!$this->isIdExpr($expr->name)) {
            return null;
        }
        $module = $this->resolvePythonModuleAlias($expr->class);
        if ($module === null) {
            return null;
        }

        $method = $this->parseIdentifier($expr->name);
        $target = $this->getPythonModuleExpression($module);
        $methodName = $this->getLiteralString($method);
        if ($expr->args === []) {
            return $target . '.call(' . $methodName . ')';
        }
        return $target . '.call(' . $methodName . ', ' . $this->parseCallArgs($expr->args) . ')';
    }

    protected function parsePythonModuleStaticPropertyFetch(Expr\StaticPropertyFetch $expr): ?string
    {
        if (!$this->isIdExpr($expr->name)) {
            return null;
        }
        $module = $this->resolvePythonModuleAlias($expr->class);
        if ($module === null) {
            return null;
        }

        return $this->getPythonModuleExpression($module)
            . '.attr(' . $this->getLiteralString($this->parseIdentifier($expr->name)) . ')';
    }

    protected function rejectPythonModuleClassConstantFetch(Expr\ClassConstFetch $expr): void
    {
        if (!$this->isIdExpr($expr->name) || $this->resolvePythonModuleAlias($expr->class) === null) {
            return;
        }

        $alias = $this->parseIdentifier($expr->class);
        $member = $this->parseIdentifier($expr->name);
        $this->fatalError(
            $expr,
            "Python module value `{$alias}::{$member}` must use `{$alias}::\${$member}`",
        );
    }

    protected function genPythonModuleDataDeclarations(): string
    {
        if (!$this->pythonRuntimeUsed) {
            return '';
        }

        $code = 'extern THREAD_LOCAL bool ' . self::PREFIX . 'python_runtime_configured;' . PHP_EOL
            . 'void ' . self::PREFIX . 'configure_python_runtime();' . PHP_EOL;
        if ($this->pythonModuleMap !== []) {
            $code .= 'extern THREAD_LOCAL zval ' . self::PREFIX . 'python_module_map['
                . count($this->pythonModuleMap) . '];' . PHP_EOL
                . 'php::Object ' . self::PREFIX
                . 'get_python_module(int module_id, const php::Str &module_name);' . PHP_EOL;
        }
        return $code;
    }

    protected function genPythonModuleStorage(): string
    {
        if (!$this->pythonRuntimeUsed) {
            return '';
        }

        $code = "// python runtime \n"
            . 'THREAD_LOCAL bool ' . self::PREFIX . 'python_runtime_configured = false;' . PHP_EOL;
        if ($this->pythonModuleMap !== []) {
            $code .= 'THREAD_LOCAL zval ' . self::PREFIX . 'python_module_map['
                . count($this->pythonModuleMap) . ']{};' . PHP_EOL;
        }
        return $code;
    }

    protected function genPythonModuleGetter(): string
    {
        if (!$this->pythonRuntimeUsed) {
            return '';
        }

        $pyCoreClass = $this->getClassEntryPtr('PyCore');
        $setOptionsFunction = $this->getFuncPtr('PyCore::setOptions');
        $returnAsObject = $this->getLiteralString('return_as_object');

        $code = 'void ' . self::PREFIX . 'configure_python_runtime() {' . PHP_EOL
            . 'if (EXPECTED(' . self::PREFIX . 'python_runtime_configured)) {' . PHP_EOL
            . 'return;' . PHP_EOL
            . '}' . PHP_EOL
            . 'php::Array options;' . PHP_EOL
            . 'options.set(' . $returnAsObject . ', true);' . PHP_EOL
            . 'php::call(' . $pyCoreClass . ', ' . $setOptionsFunction . ', php::ArgList{options});' . PHP_EOL
            . self::PREFIX . 'python_runtime_configured = true;' . PHP_EOL
            . '}' . PHP_EOL . PHP_EOL;

        if ($this->pythonModuleMap === []) {
            return $code;
        }

        $importFunction = $this->getFuncPtr('PyCore::import');

        return $code
            . 'php::Object ' . self::PREFIX
            . 'get_python_module(int module_id, const php::Str &module_name) {' . PHP_EOL
            . self::PREFIX . 'configure_python_runtime();' . PHP_EOL
            . 'zval *module = &' . self::PREFIX . 'python_module_map[module_id];' . PHP_EOL
            . 'if (UNEXPECTED(Z_ISUNDEF_P(module))) {' . PHP_EOL
            . 'auto ce = ' . $pyCoreClass . ';' . PHP_EOL
            . 'auto fn = ' . $importFunction . ';' . PHP_EOL
            . 'php::Variant imported = php::call(ce, fn, php::ArgList{module_name});' . PHP_EOL
            . '(void) php::Object(imported);' . PHP_EOL
            . 'imported.moveTo(module);' . PHP_EOL
            . '}' . PHP_EOL
            . 'return php::Object(module);' . PHP_EOL
            . '}' . PHP_EOL . PHP_EOL;
    }

    protected function genPythonModuleCleanup(): string
    {
        if (!$this->pythonRuntimeUsed) {
            return '';
        }

        $code = '';
        if ($this->pythonModuleMap !== []) {
            $code .= 'for (zval &module : ' . self::PREFIX . 'python_module_map) {' . PHP_EOL
                . 'if (!Z_ISUNDEF(module)) {' . PHP_EOL
                . 'zval_ptr_dtor(&module);' . PHP_EOL
                . 'ZVAL_UNDEF(&module);' . PHP_EOL
                . '}' . PHP_EOL
                . '}' . PHP_EOL;
        }
        return $code . self::PREFIX . 'python_runtime_configured = false;' . PHP_EOL;
    }
}
