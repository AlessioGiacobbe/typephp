<?php
/**
 * This file is part of TypePHP.
 *
 * Resolves static and dynamic class constant fetches.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;
use TypePhp\Generator\Symbol;

trait ClassConstantFetchTrait
{
    protected function parseClassConstFetch(Expr\ClassConstFetch $expr): string
    {
        if (!$this->isNameExpr($expr->class)) {
            $this->assertNotNativeObjectDynamicClassTarget($expr->class, $expr);
        }
        $this->rejectPythonModuleClassConstantFetch($expr);

        if (!$this->isIdExpr($expr->name)) {
            return $this->parseDynamicClassConstNameFetch($expr);
        }

        if (!$this->isNameExpr($expr->class)) {
            return $this->parseDynamicClassConstFetch($expr);
        }

        $class = $this->parseIdentifier($expr->class);
        $self = false;
        if ($class === 'self' or $class === 'this_') {
            $self = true;
            // Trait methods are compiled only after their AST is composed into
            // the consuming class. During trait preprocessing, however, class
            // constant initializers still belong to the trait itself. Resolve
            // `self` lexically in both cases; rewriting it to `static` would
            // incorrectly require a method scope for expressions such as
            // `const B = [...self::A]`.
            $class = '\\' . $this->getFullClassName();
        } elseif ($class === 'parent') {
            if (!$this->classDef || !$this->classDef->extends) {
                $this->fatalError($expr, 'Cannot use "parent" outside a class or class does not extend any class');
            }
            // extends is already fully resolved. Keep the leading slash so the
            // current namespace is not applied again below.
            $class = '\\' . $this->classDef->extends;
            $self = true;
        }

        $const = $this->escapeString($this->parseIdentifier($expr->name));
        if ($class === 'static') {
            if ($this->classDef?->nativeObject) {
                $this->fatalError(
                    $expr,
                    'Native classes do not support late static binding; use `self::` or a concrete class name',
                );
            }
            if (!$this->methodDef) {
                $this->fatalError($expr, "The 'static' keyword can only be used as the class name in class methods");
            }
            if ($const === 'class') {
                return Symbol::getCalledClass();
            } else {
                return Symbol::constant() . '(' . Symbol::getCalledCe() . ', ' . $this->getLiteralString($const) . ')';
            }
        }

        if ($self or $this->isNameExpr($expr->class)) {
            $class = $this->getNamespacedClassName($class);
        }
        if ($const === 'class') {
            if ($self or $this->isNameExpr($expr->class)) {
                return $this->getLiteralString($class);
            }
        }
        if (($self or $this->isNameExpr($expr->class)) and $this->isIdExpr($expr->name)) {
            if ($this->hasClass($class)) {
                $classDef = $this->getClass($class);
                if ($classDef->enum) {
                    $ce = $this->getClassEntryPtr($class);
                    return 'php::getEnumCase(' . $ce . ', ' . $this->getLiteralString($const) . ')';
                }
                $nativeConst = $this->findNativeClassConst($expr, $class, $const);
                if ($nativeConst) {
                    return $nativeConst;
                }
            }
            $ce = $this->getClassEntryPtr($class);
            return Symbol::constant() . '(' . $ce . ', ' . $this->getLiteralString($const) . ')';
        }
        $name = $class . '::' . $const;
        $name = $this->getLiteralString($name);
        return Symbol::constant() . '(' . $name . ')';
    }

    protected function parseDynamicClassConstFetch(Expr\ClassConstFetch $expr): string
    {
        $const = $this->escapeString($this->parseIdentifier($expr->name));
        $target = $this->materializeDynamicClassConstTarget($expr->class);

        if ($const === 'class') {
            return 'php::fn::get_class(' . $target . ')';
        }

        $className = '(' . $target . '.isObject() ? php::fn::get_class(' . $target . ') : ' . $target . ')';
        return Symbol::constant() . '(php::concat({' . $className . ', "::", ' . $this->getLiteralString($const) . '}))';
    }

    protected function parseDynamicClassConstNameFetch(Expr\ClassConstFetch $expr): string
    {
        $scope = $this->methodDef && $this->classDef
            ? $this->getClassEntryPtr($this->getFullClassName())
            : 'nullptr';

        if (!$this->isNameExpr($expr->class)) {
            // PHP evaluates the class target before the dynamic constant name.
            $target = $this->materializeDynamicClassConstOperand($expr->class, 'class constant target');
            $name = $this->materializeDynamicClassConstOperand($expr->name, 'class constant name');
            return 'php::classConstant(' . $target . ', ' . $name . ', ' . $scope . ')';
        }

        $class = $this->parseIdentifier($expr->class);
        if ($class === 'static') {
            if ($this->classDef?->nativeObject) {
                $this->fatalError(
                    $expr,
                    'Native classes do not support late static binding; use `self::` or a concrete class name',
                );
            }
            if (!$this->methodDef) {
                $this->fatalError($expr, "The 'static' keyword can only be used as the class name in class methods");
            }
            $ce = Symbol::getCalledCe();
        } elseif ($class === 'self' or $class === 'this_') {
            $ce = $this->getClassEntryPtr($this->getFullClassName());
        } elseif ($class === 'parent') {
            if (!$this->classDef || !$this->classDef->extends) {
                $this->fatalError($expr, 'Cannot use "parent" outside a class or class does not extend any class');
            }
            $ce = $this->getClassEntryPtr($this->classDef->extends);
        } else {
            $ce = $this->getClassEntryPtr($this->getNamespacedClassName($class));
        }

        $name = $this->materializeDynamicClassConstOperand($expr->name, 'class constant name');
        return 'php::classConstant(' . $ce . ', ' . $name . ', ' . $scope . ')';
    }

    protected function materializeDynamicClassConstTarget(NodeAbstract $expr): string
    {
        return $this->materializeDynamicClassConstOperand($expr, 'class constant target');
    }

    protected function materializeDynamicClassConstOperand(NodeAbstract $expr, string $description): string
    {
        $this->assertExprCanBeUsedAsValue($expr, $description);
        [$value, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
        $tmpVar = $this->addTmpVar(Type::VAR);
        $this->appendCapturedStmtLinesToContext($beforeStmts);
        $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $value . ';';
        $this->appendCapturedStmtLinesToContext($afterStmts);
        return $tmpVar;
    }

}
