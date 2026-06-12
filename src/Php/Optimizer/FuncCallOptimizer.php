<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Optimizer;

use PhpAot\Php\Reflection;
use PhpParser\Node;

/**
 * Config-driven optimizer for built-in function calls.
 *
 * Used as a trait by CompilerBase. All method calls are direct (no __call/reflection).
 */
trait FuncCallOptimizer
{
    private const string A_V = 'v';
    private const string A_S = 's';
    private const string A_I = 'i';
    private const string A_F = 'f';
    private const string A_B = 'b';
    private const string A_R = 'R';
    private const string A_OPT = '?';

    private const int FOLD_STRING_LEN = 1;
    private const int FOLD_STRING_CASE = 2;
    private const int FOLD_CMP2 = 3;
    private const int FOLD_CMP3 = 4;
    private const int FOLD_COUNT_LITERAL = 5;
    private const int FOLD_KNOWN_CLASS = 6;
    private const int FOLD_KNOWN_CONSTANT = 7;
    private const int FOLD_SSA_TYPE = 8;

    /** @var array<string,string|array>|null */
    private ?array $_funcCallConfig = null;

    /** @var array<string,array> Cache for auto-detected arg reflection info */
    private array $_autoArgTypes = [];

    // =========================================================================
    // Config
    // =========================================================================

    private function getFuncCallConfig(): array
    {
        if ($this->_funcCallConfig !== null) {
            return $this->_funcCallConfig;
        }
        return $this->_funcCallConfig = $this->buildFuncCallConfig();
    }

    private function buildFuncCallConfig(): array
    {
        $simple = [
            'urlencode', 'urldecode', 'rawurlencode', 'rawurldecode',
            'base64_encode', 'method_exists', 'property_exists',
            'implode', 'str_replace', 'array_column', 'array_reverse',
            'array_sum', 'array_product', 'array_key_first', 'array_key_last',
            'array_combine', 'array_flip', 'array_intersect', 'array_values',
            'version_compare', 'gettype',
            'is_array', 'is_string', 'is_object', 'is_resource',
            'is_scalar', 'is_numeric', 'is_callable', 'is_countable', 'is_iterable',
            'array_is_list', 'is_dir', 'is_file', 'realpath', 'time',
            'parse_url', 'base64_decode',
            'in_array', 'array_search', 'array_unique', 'array_filter', 'array_reduce',
            'date', 'strtotime', 'md5', 'print_r',
            'strstr', 'strripos', 'strrpos', 'is_a', 'is_subclass_of',
            'sort', 'rsort', 'asort', 'arsort', 'ksort',
            'array_pop', 'array_shift', 'reset', 'end',
            'microtime', 'hrtime', 'uniqid',
            'dirname', 'basename',
        ];

        $extra = [
            // Aliases (PHP function name → C++ target name)
            'join'             => 'implode',
            'str_ireplace'     => 'str_replace',
            'stristr'          => 'strstr',

            'strlen'            => ['constFold' => self::FOLD_STRING_LEN],
            'ord'               => [],
            'ucfirst'           => [],
            'lcfirst'           => [],
            'strtolower'        => ['constFold' => self::FOLD_STRING_CASE],
            'strtoupper'        => ['constFold' => self::FOLD_STRING_CASE],
            'crc32'             => [],
            'chr'               => [],
            'strcmp'            => ['constFold' => self::FOLD_CMP2],
            'strcasecmp'        => ['constFold' => self::FOLD_CMP2],
            'str_starts_with'   => [],
            'str_ends_with'     => [],
            'str_contains'      => [],
            'strtr'             => [],
            'strncmp'           => ['constFold' => self::FOLD_CMP3],
            'strncasecmp'       => ['constFold' => self::FOLD_CMP3],
            'htmlspecialchars'  => [],
            'htmlentities'      => [],
            'htmlspecialchars_decode' => [],
            'html_entity_decode' => [],
            'strip_tags'        => [],
            'explode'           => [],
            'strpos'            => [],
            'stripos'           => [],
            'substr'            => [],
            'str_repeat'        => [],
            'str_pad'           => [],
            'array_slice'       => [],
            'array_chunk'       => [],
            'array_fill'        => [],

            // Variadic
            'array_diff'         => ['variadic' => true],
            'array_merge'        => ['variadic' => true],
            'array_merge_recursive' => ['variadic' => true],

            // Compile-time fold with defaults
            'class_exists'       => ['constFold' => self::FOLD_KNOWN_CLASS, 'defaults' => [1 => 'true']],
            'interface_exists'   => ['defaults' => [1 => 'true']],
            'trait_exists'       => ['defaults' => [1 => 'true']],
            'enum_exists'        => ['defaults' => [1 => 'true']],
            'defined'            => ['constFold' => self::FOLD_KNOWN_CONSTANT],

            // Big* dispatch
            'abs' => ['bigDispatch' => [
                self::TYPE_BIGINT => 'php::BigInt::abs',
                self::TYPE_BIGFLOAT => 'php::BigFloat::abs',
                self::TYPE_DECIMAL => 'php::Decimal::abs',
            ]],
            'pow' => ['bigDispatch' => [
                self::TYPE_BIGINT => 'php::BigInt::pow',
                self::TYPE_DECIMAL => 'php::Decimal::pow',
            ]],
            'sqrt' => ['bigDispatch' => [
                self::TYPE_BIGINT => 'php::BigInt::sqrt',
                self::TYPE_DECIMAL => 'php::Decimal::sqrt',
                self::TYPE_BIGFLOAT => 'php::BigFloat::sqrt',
            ]],
            'floor' => ['bigDispatch' => [
                self::TYPE_DECIMAL => 'php::Decimal::floor',
                'fallback' => 'php::std::floor',
            ]],
            'ceil' => ['bigDispatch' => [
                self::TYPE_DECIMAL => 'php::Decimal::ceil',
                'fallback' => 'php::std::ceil',
            ]],

            // Type conversions
            'strval'   => ['conversion' => self::A_S],
            'intval'   => ['conversion' => self::A_I],
            'floatval' => ['conversion' => self::A_F],
            'boolval'  => ['conversion' => self::A_B],

            // SSA compile-time type checks
            'is_int'    => ['constFold' => self::FOLD_SSA_TYPE, 'constFoldExtra' => self::TYPE_INT],
            'is_float'  => ['constFold' => self::FOLD_SSA_TYPE, 'constFoldExtra' => self::TYPE_FLOAT],
            'is_bool'   => ['constFold' => self::FOLD_SSA_TYPE, 'constFoldExtra' => self::TYPE_BOOL],

            // Custom handlers
            'is_null'            => ['handler' => 'genIsNull'],
            'get_class'          => ['handler' => 'genGetClassOptimized'],
            'get_parent_class'   => ['handler' => 'genGetParentClass'],
            'function_exists'    => ['handler' => 'genFunctionExistsOptimized'],
            'func_get_arg'       => ['handler' => 'genFuncGetArgOptimized'],
            'func_get_args'      => ['handler' => 'genFuncGetArgsOptimized'],
            'func_num_args'      => ['handler' => 'genFuncNumArgsOptimized'],
            'compact'            => ['handler' => 'genCompactOptimized'],
            'max'                => ['handler' => 'genMaxMin'],
            'min'                => ['handler' => 'genMaxMin'],
            'array_keys'         => ['handler' => 'genArrayKeys'],
            'array_key_exists'   => ['handler' => 'genArrayKeyExists'],
            'round'              => ['handler' => 'genRound'],
            'count'              => ['handler' => 'genCount'],
            'define'             => ['handler' => 'genDefine'],
        ];

        $config = $extra;
        foreach ($simple as $name) {
            if (!isset($config[$name])) {
                $config[$name] = [];
            }
        }
        return $config;
    }

    // =========================================================================
    // Main entry point
    // =========================================================================

    protected function parseFuncCallWithOptimizer(string $name, Node\Expr\FuncCall $expr): string|false
    {
        $config = $this->getFuncCallConfig()[$name] ?? null;
        if ($config === null) {
            return false;
        }

        if (is_string($config)) {
            $targetName = $config;
            $config = ['target' => $targetName];
            $name = $targetName;
        }

        if (isset($config['handler'])) {
            return $this->{$config['handler']}($name, $expr, $config);
        }
        if (isset($config['bigDispatch'])) {
            return $this->dispatchBigType($expr, $config['bigDispatch']);
        }
        if (isset($config['conversion'])) {
            return $this->dispatchConversion($expr, $config['conversion']);
        }

        return $this->dispatchFuncCall($name, $expr, $config);
    }

    // =========================================================================
    // Generic dispatcher
    // =========================================================================

    private function dispatchFuncCall(string $name, Node\Expr\FuncCall $expr, array $config): string|false
    {
        $target = $config['target'] ?? null;
        if ($target === null) {
            $target = 'php::std::' . $name;
        } elseif (!str_starts_with($target, 'php::')) {
            $target = 'php::std::' . $target;
        }

        $refInfo = $this->getArgReflectionInfo($name);
        $argTypeStr = $config['args'] ?? ($refInfo['args'] ?? '');
        $defaults = $config['defaults'] ?? [];

        if (!empty($config['variadic']) || ($refInfo['variadic'] ?? false)) {
            return $this->genVariadicCall($target, $expr);
        }

        if (isset($config['constFold'])) {
            $folded = $this->tryConstFold($config['constFold'], $config['constFoldExtra'] ?? null, $expr);
            if ($folded !== false) {
                return $folded;
            }
        }

        $args = $this->buildArgList($expr, $argTypeStr, $defaults);
        return $target . '(' . implode(', ', $args) . ')';
    }

    // =========================================================================
    // Auto-detect argument types from PHP reflection
    // =========================================================================

    private function getArgReflectionInfo(string $funcName): array
    {
        if (isset($this->_autoArgTypes[$funcName])) {
            return $this->_autoArgTypes[$funcName];
        }

        $ref = Reflection::getFunction($funcName);
        if (!$ref) {
            return $this->_autoArgTypes[$funcName] = ['args' => '', 'variadic' => false];
        }

        $types = [];
        $variadic = false;
        foreach ($ref->getParameters() as $param) {
            if ($param->isVariadic()) {
                $variadic = true;
                continue;
            }
            $char = $this->phpParamToArgChar($param);
            if ($param->isOptional()) {
                $char = self::A_OPT . $char;
            }
            $types[] = $char;
        }

        return $this->_autoArgTypes[$funcName] = ['args' => implode('_', $types), 'variadic' => $variadic];
    }

    private function phpParamToArgChar(\ReflectionParameter $param): string
    {
        if ($param->isPassedByReference()) {
            return self::A_R;
        }
        $type = $param->getType();
        if ($type instanceof \ReflectionNamedType) {
            return match ($type->getName()) {
                'string' => self::A_S,
                'int' => self::A_I,
                'float' => self::A_F,
                'bool' => self::A_B,
                default => self::A_V,
            };
        }
        return self::A_V;
    }

    // =========================================================================
    // Arg helpers
    // =========================================================================

    private function getArg(Node\Expr\FuncCall $expr, int $i): string
    {
        return $this->parseIdentifier($expr->args[$i]->value);
    }

    private function getRefArg(Node\Expr\FuncCall $expr, int $i): string
    {
        $arg = $expr->args[$i]->value;
        if ($this->isArrayDimFetch($arg) and $this->isVarExpr($arg->var)) {
            $array = $this->parseIdentifier($arg->var);
            if ($arg->dim !== null) {
                $tmpRef = $this->genTmpVarName();
                $this->context->beforeStmtLines[] = 'auto&& ' . $tmpRef . ' = ' . $array . '.item(' . $this->identifierToStr($arg->dim) . ', true);';
                return $tmpRef;
            }
        }
        if ($this->isPropertyFetch($arg) and $this->isVarExpr($arg->var)) {
            $obj = $this->parseIdentifier($arg->var);
            $tmpRef = $this->genTmpVarName();
            $this->context->beforeStmtLines[] = 'auto&& ' . $tmpRef . ' = ' . $obj . '.attr(' . $this->identifierToStr($arg->name) . ', true);';
            return $tmpRef;
        }
        return $this->getArg($expr, $i);
    }

    private function resolveArg(Node\Expr\FuncCall $expr, int $index, string $type): string
    {
        $base = ($type[0] ?? '') === self::A_OPT ? substr($type, 1) : $type;

        $raw = ($base === self::A_R) ? $this->getRefArg($expr, $index) : $this->getArg($expr, $index);

        return match ($base) {
            self::A_S => $this->convertStringExpr($raw),
            self::A_I => $this->convertIntExpr($raw),
            self::A_F => $this->convertFloatExpr($raw),
            self::A_B => $this->convertBoolExpr($raw),
            default => $raw,
        };
    }

    private function buildArgList(Node\Expr\FuncCall $expr, string $argTypeStr, array $defaults = []): array
    {
        if ($argTypeStr === '') {
            return [];
        }

        $types = explode('_', $argTypeStr);
        $argCount = count($expr->args);
        $args = [];

        foreach ($types as $i => $type) {
            $optional = ($type[0] ?? '') === self::A_OPT;
            if ($optional && $argCount <= $i) {
                if (isset($defaults[$i])) {
                    $args[] = $defaults[$i];
                }
                continue;
            }
            $args[] = $this->resolveArg($expr, $i, $type);
        }

        return $args;
    }

    // =========================================================================
    // Variadic, conversion, Big* dispatch
    // =========================================================================

    private function genVariadicCall(string $target, Node\Expr\FuncCall $expr): string
    {
        $args = [];
        foreach ($expr->args as $arg) {
            $args[] = $this->parseExpr($arg->value);
        }
        return $target . '({' . implode(', ', $args) . '})';
    }

    private function dispatchConversion(Node\Expr\FuncCall $expr, string $convType): string
    {
        $arg = $expr->args[0]->value;
        $type = $this->detectTypeOfExpr($arg);
        $parsed = $this->parseExpr($arg);

        if ($convType === self::A_S) {
            return match ($type) {
                self::TYPE_BIGINT => 'php::BigInt::toString(' . $parsed . ')',
                self::TYPE_BIGFLOAT => 'php::BigFloat::toString(' . $parsed . ')',
                self::TYPE_DECIMAL => 'php::Decimal::toString(' . $parsed . ')',
                default => $this->convertStringExpr($parsed),
            };
        }

        return match ($convType) {
            self::A_I => $this->convertIntExpr($parsed),
            self::A_F => $this->convertFloatExpr($parsed),
            self::A_B => $this->convertBoolExpr($parsed),
            default => $parsed,
        };
    }

    private function dispatchBigType(Node\Expr\FuncCall $expr, array $dispatch): string|false
    {
        $type = $this->detectTypeOfExpr($expr->args[0]->value);
        $target = $dispatch[$type] ?? $dispatch['fallback'] ?? null;
        if (!$target) {
            return false;
        }

        $args = [$this->parseExpr($expr->args[0]->value)];
        if (count($expr->args) >= 2) {
            $args[] = $this->parseExpr($expr->args[1]->value);
        }

        return $target . '(' . implode(', ', $args) . ')';
    }

    // =========================================================================
    // Constant folding
    // =========================================================================

    private function tryConstFold(int $rule, mixed $extra, Node\Expr\FuncCall $expr): string|false
    {
        return match ($rule) {
            self::FOLD_STRING_LEN => $this->doFoldStringLen($expr),
            self::FOLD_STRING_CASE => $this->doFoldStringCase($expr),
            self::FOLD_CMP2 => $this->doFoldCmp2($expr),
            self::FOLD_CMP3 => $this->doFoldCmp3($expr),
            self::FOLD_COUNT_LITERAL => $this->doFoldCountLiteral($expr),
            self::FOLD_KNOWN_CLASS => $this->doFoldKnownClass($expr),
            self::FOLD_KNOWN_CONSTANT => $this->doFoldKnownConstant($expr),
            self::FOLD_SSA_TYPE => $this->doFoldSsaType($expr, $extra),
            default => false,
        };
    }

    private function doFoldStringLen(Node\Expr\FuncCall $expr): string|false
    {
        $arg = $expr->args[0]->value;
        return ($arg instanceof Node\Scalar\String_)
            ? strlen($arg->value) . $this->getPlatform()->getIntegerLiteralSuffix()
            : false;
    }

    private function doFoldStringCase(Node\Expr\FuncCall $expr): string|false
    {
        $arg = $expr->args[0]->value;
        if (!$this->isScalarString($arg)) {
            return false;
        }
        $func = $expr->name instanceof Node\Name ? $expr->name->toLowerString() : '';
        $val = $func === 'strtoupper' ? strtoupper($arg->value) : strtolower($arg->value);
        return $this->getLiteralString($val);
    }

    private function doFoldCmp2(Node\Expr\FuncCall $expr): string|false
    {
        $a0 = $expr->args[0]->value;
        $a1 = $expr->args[1]->value;
        if (!$this->isScalarString($a0) || !$this->isScalarString($a1)) {
            return false;
        }
        $func = $expr->name instanceof Node\Name ? $expr->name->toLowerString() : '';
        $result = $func === 'strcasecmp'
            ? strcasecmp($a0->value, $a1->value)
            : strcmp($a0->value, $a1->value);
        return $result . $this->getPlatform()->getIntegerLiteralSuffix();
    }

    private function doFoldCmp3(Node\Expr\FuncCall $expr): string|false
    {
        $a0 = $expr->args[0]->value;
        $a1 = $expr->args[1]->value;
        $a2 = $expr->args[2]->value;
        if (!$this->isScalarString($a0) || !$this->isScalarString($a1) || !$this->isScalarInt($a2)) {
            return false;
        }
        $func = $expr->name instanceof Node\Name ? $expr->name->toLowerString() : '';
        $result = $func === 'strncasecmp'
            ? strncasecmp($a0->value, $a1->value, (int) $a2->value)
            : strncmp($a0->value, $a1->value, (int) $a2->value);
        return $result . $this->getPlatform()->getIntegerLiteralSuffix();
    }

    private function doFoldCountLiteral(Node\Expr\FuncCall $expr): string|false
    {
        if (count($expr->args) !== 1 || !($expr->args[0] instanceof Node\Arg)) {
            return false;
        }
        $arg = $expr->args[0]->value;
        if ($arg instanceof Node\Expr\Array_) {
            return count($arg->items) . $this->getPlatform()->getIntegerLiteralSuffix();
        }
        return $this->genStdContainerCount($arg);
    }

    private function doFoldKnownClass(Node\Expr\FuncCall $expr): string|false
    {
        $cn = $expr->args[0]->value;
        return ($this->isScalarString($cn) && $this->hasClass($cn->value)) ? 'true' : false;
    }

    private function doFoldKnownConstant(Node\Expr\FuncCall $expr): string|false
    {
        $cn = $expr->args[0]->value;
        return ($this->isScalarString($cn) && $this->hasConstant($cn->value)) ? 'true' : false;
    }

    private function doFoldSsaType(Node\Expr\FuncCall $expr, mixed $expectType): string|false
    {
        if (count($expr->args) !== 1 || !($expr->args[0] instanceof Node\Arg)) {
            return false;
        }
        return ($this->detectTypeOfExpr($expr->args[0]->value) === $expectType) ? 'true' : false;
    }

    // =========================================================================
    // Custom handlers
    // =========================================================================

    private function genIsNull(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        return $this->parseIdentifier($e->args[0]->value) . '.isNull()';
    }

    private function genGetClassOptimized(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        $obj = $e->args[0]->value;
        if ($this->isVarExpr($obj) && $this->isTypedObject($obj->name)) {
            return $this->getLiteralString($this->getObjectType($obj->name));
        }
        return 'php::std::get_class(' . $this->parseIdentifier($obj) . ')';
    }

    private function genGetParentClass(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        if (count($e->args) === 0) {
            if ($this->classDef && $this->classDef->extends) {
                return $this->getLiteralString($this->classDef->extends);
            }
            return 'false';
        }
        $arg = $e->args[0]->value;
        if ($this->isScalarString($arg)) {
            $cls = $this->getClass($arg->value);
            if ($cls && $cls->extends) return $this->getLiteralString($cls->extends);
            if ($cls && !$cls->extends) return 'false';
        }
        return 'php::std::get_parent_class(' . $this->parseIdentifier($arg) . ')';
    }

    private function genFunctionExistsOptimized(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        return $this->genFunctionExists($n, $e);
    }

    private function genFuncGetArgOptimized(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        return $this->genFuncGetArg($n, $e);
    }

    private function genFuncGetArgsOptimized(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        return $this->genFuncGetArgs($n, $e);
    }

    private function genFuncNumArgsOptimized(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        return $this->genFuncNumArgs($n, $e);
    }

    private function genCompactOptimized(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        return $this->genCompactOrig($e);
    }

    private function genMaxMin(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        $target = 'php::std::' . $n;
        if (count($e->args) == 1) {
            return $target . '(' . $this->getArg($e, 0) . ')';
        }
        $a = [];
        foreach ($e->args as $arg) {
            $a[] = $this->parseExpr($arg->value);
        }
        return $target . '(php::Array{' . implode(', ', $a) . '})';
    }

    private function genArrayKeys(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        $cnt = count($e->args);
        if ($cnt >= 3) {
            return 'php::std::array_keys_filter(' . $this->getArg($e, 0) . ', ' . $this->getArg($e, 1) . ', ' . $this->getArg($e, 2) . ')';
        }
        if ($cnt >= 2) {
            return 'php::std::array_keys_filter(' . $this->getArg($e, 0) . ', ' . $this->getArg($e, 1) . ', false)';
        }
        return 'php::std::array_keys(' . $this->getArg($e, 0) . ')';
    }

    private function genArrayKeyExists(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        return $this->getArg($e, 1) . '.offsetExists(' . $this->getArg($e, 0) . ')';
    }

    private function genRound(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        $type = $this->detectTypeOfExpr($e->args[0]->value);
        if ($type === self::TYPE_DECIMAL) {
            $a0 = $this->parseExpr($e->args[0]->value);
            if (count($e->args) >= 2) {
                return 'php::Decimal::round(' . $a0 . ', ' . $this->parseExpr($e->args[1]->value) . ')';
            }
            return 'php::Decimal::round(' . $a0 . ')';
        }
        $args = count($e->args);
        if ($args >= 3) {
            return 'php::std::round(' . $this->getArg($e, 0) . ', ' . $this->convertIntExpr($this->getArg($e, 1)) . ', ' . $this->convertIntExpr($this->getArg($e, 2)) . ')';
        }
        if ($args >= 2) {
            return 'php::std::round(' . $this->getArg($e, 0) . ', ' . $this->convertIntExpr($this->getArg($e, 1)) . ')';
        }
        return 'php::std::round(' . $this->getArg($e, 0) . ')';
    }

    private function genCount(string $n, Node\Expr\FuncCall $e, array $c): string
    {
        $folded = $this->doFoldCountLiteral($e);
        if ($folded !== false) return $folded;
        if (count($e->args) >= 2) {
            return 'php::std::count(' . $this->getArg($e, 0) . ', ' . $this->convertIntExpr($this->getArg($e, 1)) . ')';
        }
        return 'php::std::count(' . $this->getArg($e, 0) . ')';
    }

    private function genDefine(string $n, Node\Expr\FuncCall $e, array $c): string|false
    {
        $arg = $e->args[0]->value;
        if ($this->isScalarString($arg) && !$this->isValidDefineName($arg->value)) {
            $this->fatalError($e, 'Invalid define name `' . $arg->value . '`');
        }
        $args = count($e->args) >= 3 ? 3 : 2;
        if ($args == 3) {
            return 'php::std::define(' . $this->getArg($e, 0) . ', ' . $this->getArg($e, 1) . ', ' . $this->getArg($e, 2) . ')';
        }
        return 'php::std::define(' . $this->getArg($e, 0) . ', ' . $this->getArg($e, 1) . ')';
    }

    // =========================================================================
    // Legacy helpers
    // =========================================================================

    private function genFuncGetArgs(string $name, Node\Expr\FuncCall $expr): string
    {
        $this->warningUndefinedBehavior($expr);
        $funcDef = $this->functionDef;
        $list = [];
        foreach ($funcDef->argInfoList as $i => $argInfo) {
            if ($argInfo->variadic) {
                $tmpVar = $this->addTmpVar(self::TYPE_ARRAY);
                $this->context->beforeStmtLines[] = $this->genArray($list) . ';';
                $this->context->beforeStmtLines[] = $tmpVar . '.merge(' . $argInfo->name . ');';
                return $tmpVar;
            }
            $list[] = $argInfo->name;
        }
        return $this->genArray($list);
    }

    protected function genFuncGetArg(string $name, Node\Expr\FuncCall $expr)
    {
        $this->warningUndefinedBehavior($expr);
        $position = $expr->args[0]->value;
        if ($this->isScalarInt($position)) {
            $funcDef = $this->functionDef;
            $posInt = intval($position->value);
            foreach ($funcDef->argInfoList as $i => $argInfo) {
                if ($argInfo->variadic) {
                    return $argInfo->name . '.offsetGet(' . ($posInt - $i) . ')';
                }
                if ($i == $posInt) {
                    return $argInfo->name;
                }
            }
            $this->fatalError($expr, 'wrong parameter position `' . $posInt . '`');
        } else {
            $this->fatalError($expr, 'func_get_arg() only support scalar int');
        }
    }

    private function genFuncNumArgs(string $name, Node\Expr\FuncCall $expr): string
    {
        $this->warningUndefinedBehavior($expr);
        $funcDef = $this->functionDef;
        foreach ($funcDef->argInfoList as $i => $argInfo) {
            if ($argInfo->variadic) {
                return '(' . $argInfo->name . '.count() + ' . $i . ')';
            }
        }
        return count($funcDef->argInfoList);
    }

    protected function genFunctionExists(string $name, Node\Expr\FuncCall $expr): string
    {
        $funcName = $expr->args[0]->value;
        if ($this->isScalarString($funcName)) {
            $nameLower = strtolower(trim($funcName->value, '\\'));
            if ($this->findNativeFunction($nameLower)) {
                return 'true';
            }
            $funcName = $this->getLiteralString($nameLower);
            return 'php::std::function_exists(' . $funcName . ')';
        }
        return 'php::std::function_exists(' . $this->parseIdentifier($funcName) . ')';
    }

    private function genGetClass(Node\Expr\FuncCall $expr): string
    {
        $object = $expr->args[0]->value;
        if ($this->isVarExpr($object) and $this->isTypedObject($object->name)) {
            return $this->getLiteralString($this->getObjectType($object->name));
        }
        return 'php::std::get_class(' . $this->parseIdentifier($object) . ')';
    }

    private function genCompactOrig(Node\Expr\FuncCall $expr): string
    {
        $list = [];

        foreach ($expr->args as $arg) {
            if (!$this->isScalarString($arg->value)) {
                $this->fatalError($expr, 'The argument of compact function can only be literal string');
            }
            $var = $arg->value->value;
            if (!$this->hasVar($var)) {
                $this->errorUndefinedVariable($var);
            }
            if ($this->isSuperGlobal($var)) {
                $this->fatalError($expr, 'Cannot use super global variable `' . $var . '` in compact function');
            }

            $key = $this->getLiteralString($var);
            $cVar = $this->escapeVarName($var);
            $list[] = '{' . $key . ', php::Var(' . $cVar . ')}';
        }

        return 'php::Array{' . implode(', ', $list) . '}';
    }

    // =========================================================================
    // Utility
    // =========================================================================

    protected function isValidDefineName(string $name): bool
    {
        return preg_match('/^(?!\d)[\p{L}_][\p{L}\p{N}_]*$/u', $name) === 1;
    }
}
