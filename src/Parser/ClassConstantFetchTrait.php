<?php
/**
 * This file is part of TypePHP.
 *
 * Resolves static and dynamic class constant fetches.
 */

namespace TypePhp\Parser;

use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;
use TypePhp\Symbol;

trait ClassConstantFetchTrait
{
    protected function parseClassConstFetch(Expr\ClassConstFetch $expr): string
    {
        if (!$this->isNameExpr($expr->class)) {
            return $this->parseDynamicClassConstFetch($expr);
        }

        $class = $this->parseIdentifier($expr->class);
        $self = false;
        if ($class === 'self' or $class === 'this_') {
            // Trait 读取常量，必须动态获取类名
            if ($this->classDef->trait) {
                $class = 'static';
            } else {
                $self = true;
                $class = $this->class;
            }
        }

        $const = $this->escapeString($this->parseIdentifier($expr->name));
        if ($class === 'static') {
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

    protected function materializeDynamicClassConstTarget(NodeAbstract $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr, 'class constant target');
        [$value, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
        $tmpVar = $this->addTmpVar(self::TYPE_VAR);
        $this->appendCapturedStmtLinesToContext($beforeStmts);
        $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $value . ';';
        $this->appendCapturedStmtLinesToContext($afterStmts);
        return $tmpVar;
    }

}

