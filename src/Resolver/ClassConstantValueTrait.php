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
use TypePhp\Entity\ConstantDef;
use TypePhp\Entity\EnumCaseRef;

trait ClassConstantValueTrait
{
    /**
     * Prefix of the marker string an enum case evaluates to when the caller
     * asked for identity semantics (see getClassConstValue()). The NUL bytes
     * make a collision with a real string constant value impossible.
     */
    public const ENUM_CASE_IDENTITY_PREFIX = "\0enum-case\0";

    public function getDefinedConstants(): array
    {
        return $this->internalConstants;
    }

    public function getClassConstValue(NodeAbstract $expr, string $_class, string $name, string $currentClass = '', bool $enumCasesAsIdentity = false): mixed
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
                if ($enumCasesAsIdentity) {
                    // Each enum case is a distinct object in Zend: two cases
                    // are the same value only when both the enum class and
                    // the case name match, never through a shared case name
                    // or backing scalar. Callers comparing values for
                    // identity get an uncollidable marker instead.
                    return self::ENUM_CASE_IDENTITY_PREFIX
                        . strtolower(ltrim($classDef->getNamespacedName(false), '\\'))
                        . '::' . $name;
                }
                // The case IDENTITY is the constant's value; folding to the
                // backing scalar (or the case name) would make
                // `K::CONST === E::Case` false through every dynamic path.
                return new EnumCaseRef($classDef->getNamespacedName(false), $name);
            }
        }
        $this->fatalError($expr, "Class constant `{$class}::{$name}` not found");
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

    protected function evaluateClassConstValue(?NodeAbstract $origin, ConstantDef $constDef, string $class, string $name, bool $enumCasesAsIdentity = false): mixed
    {
        $valueExpr = $constDef->valueExpr;
        if (!$valueExpr instanceof Node\Expr) {
            $this->fatalError($origin, "Class constant `{$class}::{$name}` has no constant expression");
        }

        $evaluator = new ConstExprEvaluator(function (Node\Expr $expr) use ($origin, $class, $enumCasesAsIdentity) {
            if ($expr instanceof Node\Expr\ConstFetch) {
                $constName = $expr->name->toString();
                return match (strtolower($constName)) {
                    'true' => true,
                    'false' => false,
                    'null' => null,
                    default => defined($constName)
                        ? constant($constName)
                        : throw new \RuntimeException("Constant `{$constName}` not found"),
                };
            }
            if ($expr instanceof Node\Expr\ClassConstFetch && $expr->class instanceof Node\Name) {
                $constName = $expr->name->toString();
                $className = $expr->class->toString();
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
                if (strcasecmp($className, 'self') === 0 || strcasecmp($className, 'static') === 0) {
                    $className = $class;
                } elseif (strcasecmp($className, 'parent') === 0) {
                    $className = $this->getParentClass($class);
                }
                // A resolved self/parent/static target is already fully
                // qualified, and a `\App3\C` source spelling is fully
                // qualified even though Name::toString() strips the leading
                // backslash. Mark both absolute so getClassConstValue() does
                // not prepend the current file's namespace a second time
                // (`TypePhp\TypePhp\...`, `App3\App3\...`).
                if ($className !== ''
                    && ($expr->class instanceof Node\Name\FullyQualified
                        || strcasecmp($expr->class->toString(), $className) !== 0)
                ) {
                    $className = '\\' . ltrim($className, '\\');
                }
                return $this->getClassConstValue($origin ?? $expr, $className, $constName, $class, $enumCasesAsIdentity);
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
