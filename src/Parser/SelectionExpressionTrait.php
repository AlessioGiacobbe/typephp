<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers ternary, match, coalesce-style value selection, and branch temporaries.
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait SelectionExpressionTrait
{
    protected function parseTernary(Expr\Ternary $expr): string
    {
        if ($expr->if === null) {
            return $this->parseValueSelection($expr, $expr->cond, $expr->else, self::OP_NOT_EMPTY);
        }
        $this->assertExprCanBeUsedAsCondition($expr->cond, 'ternary condition');
        $this->assertExprCanBeUsedAsValue($expr->if, 'ternary branch');
        $this->assertExprCanBeUsedAsValue($expr->else, 'ternary branch');
        [$cond, $condBeforeStmts, $condAfterStmts] = $this->parseExprWithCapturedStmts($expr->cond);
        $ifBeforeStmtCount = count($this->context->beforeStmtLines);
        $ifAfterStmtCount = count($this->context->afterStmtLines);
        $if = $this->parseExpr($expr->if);
        $ifBeforeStmts = array_slice($this->context->beforeStmtLines, $ifBeforeStmtCount);
        $ifAfterStmts = array_slice($this->context->afterStmtLines, $ifAfterStmtCount);
        $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $ifBeforeStmtCount);
        $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $ifAfterStmtCount);

        $elseBeforeStmtCount = count($this->context->beforeStmtLines);
        $elseAfterStmtCount = count($this->context->afterStmtLines);
        $else = $this->parseExpr($expr->else);
        $elseBeforeStmts = array_slice($this->context->beforeStmtLines, $elseBeforeStmtCount);
        $elseAfterStmts = array_slice($this->context->afterStmtLines, $elseAfterStmtCount);
        $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $elseBeforeStmtCount);
        $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $elseAfterStmtCount);

        $hasBranchStmts = $condBeforeStmts || $condAfterStmts || $ifBeforeStmts || $ifAfterStmts || $elseBeforeStmts || $elseAfterStmts;
        $typeChanged = $this->detectTypeOfExpr($expr->if) !== $this->detectTypeOfExpr($expr->else);
        if (!$hasBranchStmts && $typeChanged) {
            $if = 'php::Var(' . $if . ')';
            $else = 'php::Var(' . $else . ')';
        }
        if ($hasBranchStmts) {
            $code = '[&]() -> ' . Type::VAR . '{';
            $code .= $this->formatCapturedStmtLines($condBeforeStmts);
            if ($condAfterStmts) {
                $condTmpVar = $this->addTmpVar(Type::VAR);
                $code .= $this->getIndent() . "{$condTmpVar} = {$cond};";
                $code .= $this->formatCapturedStmtLines($condAfterStmts);
                $cond = $condTmpVar;
            }
            $code .= $this->getIndent() . 'if (' . $cond . ') {';
            $code .= $this->formatTernaryReturn($if, $ifBeforeStmts, $ifAfterStmts);
            $code .= $this->getIndent() . '} else {';
            $code .= $this->formatTernaryReturn($else, $elseBeforeStmts, $elseAfterStmts);
            $code .= $this->getIndent() . '}';
            $code .= $this->getIndent() . '}()';
            return $code;
        }
        return '(' . $cond . ') ? (' . $if . ') : (' . $else . ')';
    }

    protected function formatTernaryReturn(string $value, array $beforeStmts, array $afterStmts): string
    {
        $code = $this->formatCapturedStmtLines($beforeStmts);
        if ($afterStmts) {
            $tmpVar = $this->addTmpVar(Type::VAR);
            $code .= $this->getIndent() . "{$tmpVar} = {$value};";
            $code .= $this->formatCapturedStmtLines($afterStmts);
            $code .= $this->getIndent() . 'return ' . $tmpVar . ';';
        } else {
            $code .= $this->getIndent() . 'return php::Var(' . $value . ');';
        }
        return $code;
    }

    protected function parseMatch(Expr\Match_ $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->cond, 'match condition');
        $var = $this->parseIdentifier($expr->cond);
        if ($this->isVarExpr($expr->cond)) {
            if (!$this->hasVar($var)) {
                $this->errorUndefinedVariable($expr->cond);
            }
        } else {
            $tmpVar = $this->addTmpVar(Type::VAR);
            $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $var . ';';
            $var = $tmpVar;
        }

        $code = '[&]() -> ' . Type::VAR . '{';
        $default = null;
        foreach ($expr->arms as $arm) {
            if ($arm->conds === null) {
                $default = $arm->body;
                continue;
            }
            $matched = $this->genTmpVarName();
            $code .= $this->getIndent() . 'bool ' . $matched . ' = false;';
            foreach ($arm->conds as $cond) {
                if ($this->isMatchExpr($cond)) {
                    $this->fatalError($arm, 'Match expression cannot be used as a condition');
                }
                $this->assertExprCanBeUsedAsValue($cond, 'match arm condition');
                [$condValue, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($cond);
                $code .= $this->getIndent() . 'if (!' . $matched . ') {';
                $code .= $this->formatCapturedStmtLines($beforeStmts);
                if ($afterStmts) {
                    $condTmpVar = $this->addTmpVar(Type::VAR);
                    $code .= $this->getIndent() . "{$condTmpVar} = {$condValue};";
                    $code .= $this->formatCapturedStmtLines($afterStmts);
                    $condValue = $condTmpVar;
                }
                $code .= $this->getIndent() . $matched . ' = php::same(' . $var . ', ' . $condValue . ');';
                $code .= $this->getIndent() . '}';
            }
            $code .= $this->getIndent() . 'if (' . $matched . ') {';
            $code .= $this->formatMatchReturn($arm->body);
            $code .= $this->getIndent() . '}';
        }

        if ($default) {
            $code .= $this->getIndent() . '{';
            $code .= $this->formatMatchReturn($default);
            $code .= $this->getIndent() . '}';
        } else {
            $code .= $this->getIndent() . '{ return php::throwException("UnhandledMatchError", "Unhandled match case"); }';
        }
        $code .= '}()';

        return $code;
    }

    protected function formatMatchReturn(NodeAbstract $body): string
    {
        $this->assertExprCanBeUsedAsValue($body, 'match arm');
        [$value, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($body);
        $code = $this->formatCapturedStmtLines($beforeStmts);
        if ($afterStmts) {
            $tmpVar = $this->addTmpVar(Type::VAR);
            $code .= $this->getIndent() . "{$tmpVar} = {$value};";
            $code .= $this->formatCapturedStmtLines($afterStmts);
            $code .= $this->getIndent() . 'return ' . $tmpVar . ';';
        } else {
            $code .= $this->getIndent() . 'return ' . $value . ';';
        }
        return $code;
    }


    protected function parseValueSelection(NodeAbstract $expr, Expr $left, Expr $right, string $op): string
    {
        $this->assertExprCanBeUsedAsValue($left, 'selection value');
        $this->assertExprCanBeUsedAsValue($right, 'selection value');
        $leftExpr = $this->parseIdentifier($left);
        if ($this->isVarExpr($left)) {
            $this->checkVarMustExist($left, $leftExpr);
        }

        $condExpr = $this->parseChainedExpr($left, $op, true);
        $chainOpResult = $left->getAttribute('chainOpResult');
        if ($chainOpResult) {
            $leftExpr = $chainOpResult;
        }

        $rightBeforeStmtCount = count($this->context->beforeStmtLines);
        $rightAfterStmtCount = count($this->context->afterStmtLines);
        $rightExpr = $this->parseIdentifier($right);
        $rightBeforeStmts = array_slice($this->context->beforeStmtLines, $rightBeforeStmtCount);
        $rightAfterStmts = array_slice($this->context->afterStmtLines, $rightAfterStmtCount);
        $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $rightBeforeStmtCount);
        $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $rightAfterStmtCount);
        $this->checkVarMustExist($right, $rightExpr);

        $tmpVar = $this->addTmpVar(Type::VAR);
        if ($rightBeforeStmts || $rightAfterStmts) {
            $code = $this->formatCppLineComment('Expr: ', $this->printer->prettyPrintExpr($expr)) . PHP_EOL .
                'if (' . $condExpr . ') {' . PHP_EOL .
                $this->getIndent() . $tmpVar . ' = ' . $leftExpr . ';' . PHP_EOL .
                '} else {' . PHP_EOL;
            if ($rightBeforeStmts) {
                $code .= $this->getIndent() . implode(PHP_EOL . $this->getIndent(), $rightBeforeStmts) . PHP_EOL;
            }
            if ($rightAfterStmts) {
                $rightTmpVar = $this->addTmpVar(Type::VAR);
                $code .= $this->getIndent() . $rightTmpVar . ' = ' . $rightExpr . ';' . PHP_EOL;
                $code .= $this->getIndent() . implode(PHP_EOL . $this->getIndent(), $rightAfterStmts) . PHP_EOL;
                $code .= $this->getIndent() . $tmpVar . ' = ' . $rightTmpVar . ';' . PHP_EOL;
            } else {
                $code .= $this->getIndent() . $tmpVar . ' = ' . $rightExpr . ';' . PHP_EOL;
            }
            $code .= '}';
            $this->context->beforeStmtLines[] = $code;
        } else {
            $this->context->beforeStmtLines[] = $this->formatCppLineComment('Expr: ', $this->printer->prettyPrintExpr($expr)) . PHP_EOL .
                $tmpVar . ' = ' . $condExpr . ' ? ' . $leftExpr . ' : ' . $rightExpr . ';';
        }
        $expr->setAttribute('replace', $tmpVar);

        return $tmpVar;
    }

}

