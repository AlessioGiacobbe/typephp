<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers foreach iteration, destructuring, and by-reference value assignment.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Foreach_;

trait ForeachTrait
{
    protected function parseForeachItemAsList(string $listTmpVar, array $listItems): string
    {
        $code = '';
        foreach ($listItems as $k => $item) {
            if (!$item) {
                continue;
            }
            if ($item instanceof ArrayItem) {
                $key = $item->key ? $this->parseArrayKey($item->key) : (string) $k;
                if ($item->value instanceof Expr\List_) {
                    $nestedTmpVar = $this->genTmpVarName();
                    $this->addLocalVar($nestedTmpVar, Type::VAR);
                    $code .= $this->getIndent() . ' ' . $nestedTmpVar . ' = ' . $listTmpVar . '.item(' . $key . ');' . PHP_EOL;
                    $code .= $this->parseForeachItemAsList($nestedTmpVar, $item->value->items);
                    continue;
                }
                $var = $this->parseWritableIdentifier($item->value);
                if ($this->isVarExpr($item->value) and !$this->hasVar($var)) {
                    $this->addLocalVar($var, Type::VAR);
                }
                $code .= $this->getIndent() . ' ' . $var . ' = ' . $listTmpVar . '.item(' . $key . ');' . PHP_EOL;
            } else {
                $this->fatalError($item, 'Unsupported foreach item type');
            }
        }
        return $code;
    }

    protected function parseForeachBody(Foreach_ $node): string
    {
        return $this->parseStmts($node->stmts) . $this->genLoopEndFlagCheck();
    }

    protected function parseForeachKeyAssignment(Foreach_ $node, string $keyExpr, string $defaultType = Type::VAR): string
    {
        if (!$node->keyVar) {
            return '';
        }

        $keyVar = $this->parseIdentifier($node->keyVar);
        $this->checkVar($node, $keyVar, $defaultType);
        return $this->getIndent() . ' ' . $keyVar . ' = ' . $keyExpr . ';' . PHP_EOL;
    }

    protected function parseForeachValueAssignment(Foreach_ $node, string $valueExpr, ?string $valueRefExpr = null): string
    {
        if ($node->byRef && $valueRefExpr === null) {
            $this->fatalError($node, 'Cannot use & with foreach');
        }

        if ($node->byRef and !$this->isVarExpr($node->valueVar)) {
            $this->fatalError($node, 'Foreach by reference only supports variable as value');
        }

        if ($node->valueVar instanceof Expr\List_) {
            if ($node->byRef) {
                $this->fatalError($node, 'Foreach by reference cannot use list destructuring');
            }
            $listTmpVar = $this->genTmpVarName();
            $this->addLocalVar($listTmpVar, Type::VAR);
            return $this->getIndent() . ' ' . $listTmpVar . ' = ' . $valueExpr . ';' . PHP_EOL
                . $this->parseForeachItemAsList($listTmpVar, $node->valueVar->items);
        }

        if ($this->isArrayDimFetch($node->valueVar)) {
            if ($node->byRef) {
                $this->fatalError($node, 'Foreach by reference only supports variable as value');
            }
            $array = $this->parseIdentifier($node->valueVar->var);
            if (!$this->hasVar($array) or $node->valueVar->dim === null) {
                abort($node->valueVar);
            }
            $dim = $this->parseIdentifier($node->valueVar->dim);
            return $this->getIndent() . "{$array}.offsetSet({$dim}, {$valueExpr});";
        }

        $valueVar = $this->parseIdentifier($node->valueVar);
        if ($node->byRef) {
            if (!$this->hasVar($valueVar)) {
                $this->addLocalVar($valueVar, Type::REF);
            } elseif ($this->getVarType($valueVar) !== Type::REF) {
                $this->fatalError($node, 'Cannot assign value to reference of type');
            }
            return $this->getIndent() . ' ' . $valueVar . ' = ' . $valueRefExpr . ';' . PHP_EOL;
        }

        if ($this->isVarExpr($node->valueVar)) {
            $this->checkVar($node, $valueVar);
        }
        return $this->getIndent() . ' ' . $valueVar . ' = ' . $valueExpr . ';' . PHP_EOL;
    }

    protected function parseForeachArray(Foreach_ $node, string $iteratorVar): string
    {
        $tmpVar = $this->genTmpVarName();
        $code = "for (auto $tmpVar = $iteratorVar.begin(); $tmpVar != $iteratorVar.end(); ++$tmpVar) {" . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->parseForeachKeyAssignment($node, $tmpVar . '.key()');
        $code .= $this->parseForeachValueAssignment($node, $tmpVar . '.value()', $tmpVar . '.valueRef()');

        $body = $this->parseForeachBody($node);
        $this->indentLevel--;

        $code .= $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= $body . PHP_EOL;

        $code .= $this->getIndent() . '}';

        return $code;
    }

    protected function parseForeachIterable(Foreach_ $node, string $iterableVar): string
    {
        $iterator = $this->genTmpVarName();
        $byRef = $node->byRef ? 'true' : 'false';
        $code = "php::ForeachIterator $iterator{{$iterableVar}, $byRef};" . PHP_EOL;
        $code .= "while ($iterator.next()) {" . PHP_EOL;
        $this->indentLevel++;

        $code .= $this->parseForeachKeyAssignment($node, $iterator . '.key()');
        $code .= $this->parseForeachValueAssignment(
            $node,
            $iterator . '.value()',
            $iterator . '.valueRef()',
        );

        $body = $this->parseForeachBody($node);
        $this->indentLevel--;

        $code .= $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= $body . PHP_EOL;
        $code .= $this->getIndent() . '}';

        return $code;
    }

    protected function parseForeach(Foreach_ $node): string
    {
        if ($this->isVarExpr($node->expr)) {
            $name = $this->parseIdentifier($node->expr);
            if ($this->hasVar($name)) {
                $type = $this->getVarType($name);
                if ($type === Type::ARRAY) {
                    return $this->parseForeachArray($node, $name);
                } elseif ($type === Type::OBJECT) {
                    if ($node->byRef) {
                        $this->fatalError($node, 'Cannot use & with foreach');
                    }
                    return $this->parseForeachIterable($node, $name);
                } elseif ($this->isStdContainerType($type)) {
                    return $this->parseForeachStdContainer($node);
                }
            }
        }

        $code = '';
        $expr = $this->parseIdentifier($node->expr);
        $code .= $this->parseBeforeStmtLines() . PHP_EOL;

        $iterableVar = $this->genTmpVarName();
        $this->addLocalVar($iterableVar, Type::VAR);

        $code .= $iterableVar . ' = ' . $expr . ';' . PHP_EOL;
        $code .= $this->parseForeachIterable($node, $iterableVar);

        return $code;
    }

    /**
     * 为了兼容已有代码，默认不使用原生类型，而是将整数和浮点数作为 php 变量处理
     * 原生 int/float/bool 类型，是不支持自动转换的，例如如果 int 计算超过最大值后，会自动转为 float，除法若不能除尽，则会转为 float
     * 某些情况下高性能计算，可能需要使用原生类型，使用 $a = std::int(0) 来显式地使用原生类型
     */
}
