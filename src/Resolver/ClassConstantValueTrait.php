<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Resolver;

use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\NodeAbstract;
use TypePhp\Entity\ClassDef;
use TypePhp\Entity\ConstantDef;
use TypePhp\Entity\EnumCaseRef;

trait ClassConstantValueTrait
{
    /**
     * Backed enum cases whose stored value expression is currently being
     * evaluated, keyed by lowercased "Enum\Fqn::CaseName". Guards the lazy
     * evaluation against self-referencing and mutually recursive case values,
     * which would otherwise recurse until the stack is exhausted.
     * @var array<string, true>
     */
    private array $enumCaseExprsInProgress = [];

    public function getDefinedConstants(): array
    {
        return $this->internalConstants;
    }

    public function getClassConstValue(NodeAbstract $expr, string $_class, string $name, string $currentClass = ''): mixed
    {
        $namespace = $this->namespace;
        if (!$namespace and $currentClass and !str_contains($_class, '\\')) {
            $namespace = $this->getNamespaceOfClass($currentClass);
        }
        $class = $this->getNamespacedClassName($_class, $namespace);
        $nativeConst = $this->findNativeClassConst(
            $expr,
            $class,
            $name,
            $currentClass !== '' ? $currentClass : null,
        );
        if ($nativeConst and $expr->hasAttribute('nativeConst')) {
            $constDef = $expr->getAttribute('nativeConst');
            if ($constDef->valueExpr !== null) {
                return $this->evaluateClassConstValue($expr, $constDef, $class, $name);
            }
            if ($constDef->class !== '') {
                $refConst = $constDef->class . '::' . $name;
                if (defined($refConst)) {
                    return constant($refConst);
                }
            }
        }
        if ($this->isInternalClass($class)) {
            $constName = $class . '::' . $name;
            if (defined($constName)) {
                $value = constant($constName);
                // Internal enum cases (and internal constants holding one)
                // must keep their identity through constant evaluation.
                return $value instanceof \UnitEnum
                    ? new EnumCaseRef(get_class($value), $value->name)
                    : $value;
            }
        }
        [$inheritedFound, $inherited] = $this->resolveInheritedClassConst($class, $name);
        if ($inheritedFound) {
            return $inherited;
        }
        if ($this->hasClass($class)) {
            $classDef = $this->getClass($class);
            if ($classDef->enum && array_key_exists($name, $classDef->enumCases)) {
                if (isset($classDef->enumCaseExprs[$name])) {
                    // A backed case value beyond a scalar literal was kept as
                    // an expression AST during prepare; the full symbol table
                    // exists now, so evaluate once and memoize the result.
                    $this->evaluateEnumCaseExpr($expr, $classDef, $class, $name);
                }
                // The case IDENTITY is the constant's value; folding to the
                // backing scalar (or the case name) would make
                // `K::CONST === E::Case` false through every dynamic path.
                return new EnumCaseRef($classDef->getNamespacedName(false), $name);
            }
        }
        $this->fatalError($expr, "Class constant `{$class}::{$name}` not found");
    }

    /**
     * Evaluate a backed enum case value kept as an expression AST during
     * prepare (ClassDef::$enumCaseExprs) and memoize it into $enumCases. The
     * stored AST survives until evaluation succeeds, so an aborted evaluation
     * never leaves a half-initialized null behind, and two rules govern the
     * evaluation itself:
     *
     * - Cycle guard: a case expression may (transitively) fetch the very case
     *   it declares. Zend detects this while updating the constant and fails
     *   with "Cannot declare self-referencing constant E::A"; without a guard
     *   the compiler would recurse here until the stack is exhausted. The
     *   case is marked in progress for the duration of its evaluation
     *   (mirroring CONST_RECURSIVE on a Zend class-constant fetch), so the
     *   reported name is the first case fetched again while its own value is
     *   still being computed: `E::A` for `case A = E::A;` and `E::B` for
     *   `case A = E::B; case B = E::A;` (both probed on Zend 8.4.13).
     *
     * - Declaration context: the first fetch of the case may happen while the
     *   translator is converting a different file. Names inside the stored
     *   expression must resolve against the namespace and `use` imports of
     *   the file declaring the enum, not the current conversion context.
     */
    private function evaluateEnumCaseExpr(NodeAbstract $expr, ClassDef $classDef, string $class, string $name): void
    {
        $enumName = $classDef->getNamespacedName(false);
        $key = strtolower($enumName . '::' . $name);
        if (isset($this->enumCaseExprsInProgress[$key])) {
            $this->fatalError($expr, "Cannot declare self-referencing constant `{$enumName}::{$name}`");
        }
        $this->enumCaseExprsInProgress[$key] = true;
        try {
            $value = $this->withDeclarationNameContext(
                $classDef->namespace,
                $classDef->enumUseNamespaces,
                $classDef->enumUseAliases,
                $classDef->enumUseFunctions,
                $classDef->enumUseConstants,
                fn (): mixed => $this->evaluateConstantExpression($expr, $classDef->enumCaseExprs[$name], $class),
            );
        } finally {
            unset($this->enumCaseExprsInProgress[$key]);
        }
        $classDef->enumCases[$name] = $value;
        unset($classDef->enumCaseExprs[$name]);
    }

    /** @return array{bool, mixed} */
    protected function resolveInheritedClassConst(string $class, string $name): array
    {
        $current = ltrim($class, '\\');
        $visited = [];
        while ($current !== '' && $current !== '\\' && !isset($visited[strtolower($current)])) {
            $visited[strtolower($current)] = true;
            if ($this->hasClass($current)) {
                $classDef = $this->getClass($current);
                if ($classDef->hasConstant($name)) {
                    $constDef = $classDef->getConstant($name);
                    if ($constDef->valueExpr !== null) {
                        return [true, $this->evaluateClassConstValue(null, $constDef, $current, $name)];
                    }
                    if ($constDef->class !== '' && defined($constDef->class . '::' . $name)) {
                        return [true, constant($constDef->class . '::' . $name)];
                    }
                }
                $current = $classDef->extends;
            } elseif (($parent = $this->getParentClass($current)) !== '') {
                $current = $parent;
            } elseif (Reflection::isInternalClass($current)) {
                $constName = $current . '::' . $name;
                if (defined($constName)) {
                    $value = constant($constName);
                    return [true, $value instanceof \UnitEnum
                        ? new EnumCaseRef(get_class($value), $value->name)
                        : $value];
                }
                break;
            } else {
                break;
            }
        }
        return [false, null];
    }

    protected function evaluateClassConstValue(?NodeAbstract $origin, ConstantDef $constDef, string $class, string $name): mixed
    {
        $valueExpr = $constDef->valueExpr;
        if (!$valueExpr instanceof Node\Expr) {
            $this->fatalError($origin, "Class constant `{$class}::{$name}` has no constant expression");
        }

        return $this->evaluateConstantExpression($origin, $valueExpr, $class);
    }

    /**
     * Evaluate a constant expression AST (class constant initializer, backed
     * enum case value) with the complete symbol table of the convert phase.
     */
    protected function evaluateConstantExpression(?NodeAbstract $origin, Node\Expr $valueExpr, string $class): mixed
    {
        $evaluator = new ConstExprEvaluator(function (Node\Expr $expr) use ($origin, $class) {
            if ($expr instanceof Node\Expr\ConstFetch) {
                $constName = $expr->name->toString();
                return match (strtolower($constName)) {
                    'true' => true,
                    'false' => false,
                    'null' => null,
                    default => $this->resolveConstFetchConstantValue($origin, $expr, $class),
                };
            }
            if ($expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name) {
                $constName = $expr->name->toString();
                $className = $this->constantExpressionClassName($expr->class);
                if (strcasecmp($constName, 'class') === 0) {
                    // `::class` is a compile-time magic constant that resolves to the
                    // fully qualified class name of the referenced class.
                    if (strcasecmp($className, 'self') === 0 || strcasecmp($className, 'static') === 0) {
                        $className = $class;
                    } elseif (strcasecmp($className, 'parent') === 0) {
                        $className = $this->getParentClass($class);
                    }
                    return ltrim($this->getNamespacedClassName($className, $this->getNamespaceOfClass($class)), '\\');
                }
                if (strcasecmp($className, 'self') === 0) {
                    $className = $class;
                } elseif (strcasecmp($className, 'parent') === 0) {
                    $className = $this->getParentClass($class);
                }
                return $this->getClassConstValue($origin ?? $expr, $className, $constName, $class);
            }
            throw new \RuntimeException('Unsupported class constant expression');
        });

        return $evaluator->evaluateDirectly($valueExpr);
    }

    /**
     * The pre-AST representation of an enum case for consumers that cannot
     * register an IS_CONSTANT_AST (property and parameter defaults, attribute
     * arguments): internal enums degrade to the host case object, compiled
     * enums to the literal backing value or the case name — exactly the
     * values those paths consumed before case identity existed.
     */
    public function enumCaseLegacyValue(\TypePhp\Entity\EnumCaseRef $ref): mixed
    {
        if ($this->isInternalClass($ref->enumClass)) {
            $constName = $ref->enumClass . '::' . $ref->caseName;
            if (defined($constName)) {
                return constant($constName);
            }
        }
        if ($this->hasClass($ref->enumClass)) {
            $classDef = $this->getClass($ref->enumClass);
            if (array_key_exists($ref->caseName, $classDef->enumCases)) {
                return $classDef->enumCases[$ref->caseName] ?? $ref->caseName;
            }
        }
        return $ref->caseName;
    }

    /**
     * Class names inside a constant expression AST were already resolved by
     * the NameResolver against the file that declared the expression. Prefer
     * that resolution (the `resolvedName` attribute, or the node being fully
     * qualified) over re-resolving the bare string, which would apply the
     * namespace the translator happens to be converting when a stored
     * expression is evaluated lazily. The leading backslash keeps
     * getNamespacedClassName() from prefixing a namespace again; `self`,
     * `parent` and `static` carry no resolution and stay as written.
     */
    private function constantExpressionClassName(Node\Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) {
            return '\\' . ltrim($resolved->toString(), '\\');
        }
        if ($name instanceof Node\Name\FullyQualified) {
            return '\\' . $name->toString();
        }
        return $name->toString();
    }

    /**
     * Resolve a plain constant fetch inside a constant expression: program
     * constants declared with `const`/`define()` in the compiled sources win
     * (their initializer ASTs are recorded by parseConstDef()); anything else
     * falls back to constants defined in the compiler's own runtime
     * (PHP_INT_MAX, M_PI, ...), mirroring the previous behavior.
     */
    private function resolveConstFetchConstantValue(?NodeAbstract $origin, Node\Expr\ConstFetch $expr, string $class): mixed
    {
        $constName = $expr->name->toString();
        $candidates = [];
        $resolved = $expr->name->getAttribute('resolvedName');
        if ($resolved instanceof Node\Name) {
            $candidates[] = $resolved->toString();
        }
        // Unqualified names in a namespace fall back to the global constant;
        // the NameResolver records the namespaced candidate to try first.
        $namespaced = $expr->name->getAttribute('namespacedName');
        if ($namespaced instanceof Node\Name) {
            $candidates[] = $namespaced->toString();
        }
        $candidates[] = ltrim($constName, '\\');
        foreach ($candidates as $candidate) {
            if (!$this->hasConstant($candidate)) {
                continue;
            }
            $constInfo = $this->constants[$this->escapeConstVar($candidate)];
            if ($constInfo->valueExpr instanceof Node\Expr) {
                return $this->evaluateConstantExpression($origin, $constInfo->valueExpr, $class);
            }
        }
        if (defined($constName)) {
            return constant($constName);
        }
        throw new \RuntimeException("Constant `{$constName}` not found");
    }

    public function getConstValue(string $name): mixed
    {
        if ($this->isInternalConstant($name)) {
            $value = $this->internalConstants[$name];
            if (is_int($value)) {
                $expr = $this->genIntegerLiteral($value);
            } elseif (is_float($value)) {
                return $value;
            } elseif (is_bool($value)) {
                return $value ? 1 : 0;
            } elseif (is_string($value)) {
                return $this->genCharPtr($value);
            } else {
                $this->error('Unsupported constant type: ' . gettype($value));
            }
            return $expr;
        }
        throw new \Exception('Constant ' . $name . ' not found');
    }
}
