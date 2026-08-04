<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers nullsafe property and method access chains.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait NullsafeAccessTrait
{
    protected function parseNullsafePropertyFetch(Expr\NullsafePropertyFetch $expr): string
    {
        return $this->parseNullsafeExpr($expr);
    }

    protected function parseNullsafePropertyFetchUpdate(Expr\NullsafePropertyFetch $expr): string
    {
        return $this->parseNodeWithUpdateAttribute(
            $expr,
            self::ATTR_PROPERTY_FETCH_UPDATE,
            true,
            fn() => $this->parseNullsafePropertyFetch($expr)
        );
    }

    protected function parseNullsafeMethodCall(Expr\NullsafeMethodCall $expr): string
    {
        return $this->parseNullsafeExpr($expr);
    }

    protected function parseNullsafeExpr(
        Expr\PropertyFetch|Expr\MethodCall|Expr\NullsafePropertyFetch|Expr\NullsafeMethodCall $expr
    ): string
    {
        $list = [];
        $comment = $this->formatCppLineComment('Nullsafe Operator: ', $this->printer->prettyPrint([$expr]));

        while (1) {
            if ($expr instanceof Expr\NullsafePropertyFetch) {
                $list[] = ['property', $this->identifierToStr($expr->name, literal: true), $expr, true];
                $expr = $expr->var;
            } elseif ($expr instanceof Expr\NullsafeMethodCall) {
                $list[] = ['method', $this->identifierToStr($expr->name, literal: true), $expr->args, true];
                $expr = $expr->var;
            } elseif ($expr instanceof Expr\PropertyFetch) {
                $list[] = ['property', $this->identifierToStr($expr->name, literal: true), $expr, false];
                $expr = $expr->var;
            } elseif ($expr instanceof Expr\MethodCall) {
                $list[] = ['method', $this->identifierToStr($expr->name, literal: true), $expr->args, false];
                $expr = $expr->var;
            } else {
                if ($this->isVarExpr($expr)) {
                    $object = $this->parseIdentifier($expr);
                    if (!$this->hasVar($object)) {
                        $this->errorUndefinedVariable($expr);
                    }
                    $type = $this->getVarType($object);
                    if ($type === Type::OBJECT) {
                        break;
                    }
                }
                $object = $this->addTmpVar(Type::OBJECT);
                $this->context->beforeStmtLines[] = $this->getIndent() . $object . ' = ' . $this->parseIdentifier($expr) . ';';
                break;
            }
        }

        $list = array_reverse($list);
        $this->checkNullsafePropertyAccesses($expr, $list);
        $last = array_key_last($list);
        $tmpFn = $this->genTmpVarName();

        $code = $comment . PHP_EOL . 'auto ' . $tmpFn . ' = [&]() -> ' . Type::VAR . '{' . PHP_EOL;

        foreach ($list as $key => $item) {
            $tmpVar = $this->addTmpVar($key !== $last ? Type::OBJECT : Type::VAR);
            if ($item[3]) {
                $code .= "if ({$object}.isNull()) { return " . self::VALUE_NULL . '; }';
            }
            if ($item[0] == 'property') {
                $update = $this->escapeAttrMode($this->isPropertyFetchUpdate($item[2]));
                $code .= $this->getIndent() . "{$tmpVar} = {$object}.attr({$item[1]}, {$update});";
            } else {
                $beforeStmtCount = count($this->context->beforeStmtLines);
                $afterStmtCount = count($this->context->afterStmtLines);
                $args = $this->parseCallArgs($item[2]);
                $argBeforeStmts = array_slice($this->context->beforeStmtLines, $beforeStmtCount);
                $argAfterStmts = array_slice($this->context->afterStmtLines, $afterStmtCount);
                $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $beforeStmtCount);
                $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $afterStmtCount);
                if ($argBeforeStmts) {
                    $code .= $this->getIndent() . implode(PHP_EOL . $this->getIndent(), $argBeforeStmts) . PHP_EOL;
                }
                $code .= $this->getIndent() . "{$tmpVar} = {$object}.call({$item[1]}, {$args});";
                if ($argAfterStmts) {
                    $code .= $this->getIndent() . implode(PHP_EOL . $this->getIndent(), $argAfterStmts) . PHP_EOL;
                }
            }
            $object = $tmpVar;
        }
        $code .= $this->getIndent() . "return {$object}; };";
        $this->context->beforeStmtLines[] = $code;
        return "{$tmpFn}()";
    }

    protected function containsNullsafeChain(NodeAbstract $expr): bool
    {
        while ($expr instanceof Expr\PropertyFetch
            || $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\NullsafePropertyFetch
            || $expr instanceof Expr\NullsafeMethodCall) {
            if ($expr instanceof Expr\NullsafePropertyFetch || $expr instanceof Expr\NullsafeMethodCall) {
                return true;
            }
            $expr = $expr->var;
        }

        return false;
    }

    private function checkNullsafePropertyAccesses(NodeAbstract $baseExpr, array $list): void
    {
        $properties = [];
        foreach ($list as $item) {
            if ($item[0] !== 'property') {
                break;
            }

            /** @var Expr\NullsafePropertyFetch $node */
            $node = $item[2];
            if (!$this->isIdExpr($node->name)) {
                break;
            }

            $properties[] = [
                'node' => $node,
                'property' => $this->parseIdentifier($node->name),
            ];
        }

        if (!$properties) {
            return;
        }

        $scope = $this->class ? $this->getFullClassName() : '';
        $results = $this->createPropertyAccessResolver()->resolveNullsafePropertyChain(
            $this->detectClassOfExpr($baseExpr),
            $properties,
            $scope,
            Type::OBJECT,
        );
        foreach ($results as $index => $result) {
            $this->applyNativePropertyAccessResult($properties[$index]['node'], $result);
        }
    }

}
