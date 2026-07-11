<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers for, while, do/while, break, and continue control flow.
 */

namespace TypePhp\Parser;

use PhpParser\Node;

trait LoopControlTrait
{
    protected function parseFor(Node\Stmt\For_ $v): string
    {
        $init  = $v->init;
        $cond  = $v->cond;
        $loop  = $v->loop;
        $stmts = $v->stmts;
        $code  = '';

        $list_expr = [];
        foreach ($init as $expr) {
            [$initExpr, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
            $initExpr = $this->stringifyParsedExpr($initExpr);
            $this->appendCapturedStmtLines($code, $beforeStmts);
            $list_expr[] = $initExpr;
            if ($afterStmts) {
                $list_expr[] = implode(";\n" . $this->getIndent(), $afterStmts);
            }
        }
        $list_expr[] = '';
        $code .= implode(";\n" . $this->getIndent(), $list_expr);

        $list_cond = [];
        $list_cond_expr = [];
        $hasCondStmts = false;
        foreach ($cond as $expr) {
            $this->assertExprCanBeUsedAsCondition($expr, 'for condition');
            [$condExpr, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
            $condExpr = $this->stringifyParsedExpr($condExpr);
            $hasCondStmts = $hasCondStmts || $beforeStmts || $afterStmts;
            $list_cond[] = [$condExpr, $beforeStmts, $afterStmts];
            $list_cond_expr[] = $condExpr;
        }

        $code .= $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'for (;';
        if ($hasCondStmts) {
            $condCode = '[&]() -> bool {';
            if (empty($list_cond)) {
                $condCode .= $this->getIndent() . 'return true;';
            } else {
                $condResult = $this->genTmpVarName();
                $condCode .= $this->getIndent() . 'bool ' . $condResult . ' = true;' . PHP_EOL;
                foreach ($list_cond as [$condExpr, $beforeStmts, $afterStmts]) {
                    $this->appendCapturedStmtLines($condCode, $beforeStmts);
                    if ($afterStmts) {
                        $tmpVar = $this->addTmpVar(self::TYPE_VAR);
                        $condCode .= $this->getIndent() . $tmpVar . ' = ' . $condExpr . ';' . PHP_EOL;
                        $this->appendCapturedStmtLines($condCode, $afterStmts);
                        $condExpr = $tmpVar;
                    }
                    $condCode .= $this->getIndent() . $condResult . ' = ' . $this->convertBoolExpr($condExpr) . ';' . PHP_EOL;
                }
                $condCode .= $this->getIndent() . 'return ' . $condResult . ';';
            }
            $condCode .= $this->getIndent() . '}()';
            $code .= $condCode;
        } else {
            $code .= implode(', ', $list_cond_expr);
        }
        $code .= '; ';

        $list_loop = [];
        foreach ($loop as $expr) {
            [$loopExpr, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
            $loopExpr = $this->stringifyParsedExpr($loopExpr);
            if ($beforeStmts || $afterStmts) {
                $loopCode = '[&]() {';
                $this->appendCapturedStmtLines($loopCode, $beforeStmts);
                $loopCode .= $this->getIndent() . $loopExpr . ';' . PHP_EOL;
                $this->appendCapturedStmtLines($loopCode, $afterStmts);
                $loopCode .= $this->getIndent() . '}()';
                $list_loop[] = $loopCode;
            } else {
                $list_loop[] = $loopExpr;
            }
        }
        $code .= implode(', ', $list_loop);
        $code .= ') {' . PHP_EOL;

        $code .= $this->parseBlockStmts($stmts);
        $code .= $this->genLoopEndFlagCheck();
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    /**
     * Generate C++ code for dynamic property ++/-- operations.
     *
     * Returns null if $var is not a dynamic property fetch, so callers can
     * fall through to their normal codegen path.
     */

    protected function parseWhile(Node\Stmt\While_ $v): string
    {
        $stmts = $v->stmts;
        $this->assertExprCanBeUsedAsCondition($v->cond, 'while condition');
        [$cond, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($v->cond);

        $code = $this->parseBeforeStmtLines() . PHP_EOL;
        if ($beforeStmts || $afterStmts) {
            $code .= 'while (true) {' . PHP_EOL;
            $this->appendCapturedStmtLines($code, $beforeStmts);
            if ($afterStmts) {
                $tmpVar = $this->addTmpVar(self::TYPE_VAR);
                $code .= $this->getIndent() . $tmpVar . ' = ' . $cond . ';' . PHP_EOL;
                $this->appendCapturedStmtLines($code, $afterStmts);
                $cond = $tmpVar;
            }
            $code .= $this->getIndent() . 'if (!(' . $cond . ')) { break; }' . PHP_EOL;
        } else {
            $code .= 'while (' . $cond . ') {' . PHP_EOL;
        }
        $code .= $this->parseBlockStmts($stmts);
        $code .= $this->genLoopEndFlagCheck();
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }


    protected function parseDo(Node\Stmt\Do_ $v): string
    {
        $stmts = $v->stmts;
        $this->assertExprCanBeUsedAsCondition($v->cond, 'do-while condition');
        [$cond, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($v->cond);
        if ($beforeStmts || $afterStmts) {
            $condCode = '[&]() -> bool {';
            $this->appendCapturedStmtLines($condCode, $beforeStmts);
            if ($afterStmts) {
                $tmpVar = $this->addTmpVar(self::TYPE_VAR);
                $condCode .= $this->getIndent() . $tmpVar . ' = ' . $cond . ';' . PHP_EOL;
                $this->appendCapturedStmtLines($condCode, $afterStmts);
                $cond = $tmpVar;
            }
            $condCode .= $this->getIndent() . 'return ' . $this->convertBoolExpr($cond) . ';';
            $condCode .= $this->getIndent() . '}()';
            $cond = $condCode;
        }
        $code  = $this->parseBeforeStmtLines() . PHP_EOL;
        $code .= 'do {' . PHP_EOL;
        $code .= $this->parseBlockStmts($stmts);
        $code .= $this->genLoopEndFlagCheck();
        $code .= $this->getIndent() . '} while (' . $cond . ');' . PHP_EOL;

        return $code;
    }

    /**
     * 值选择，如 ?: 或者 ??
     */

    protected function parseBreak(Node\Stmt\Break_ $v): string
    {
        if (!$this->context->inLoop) {
            $this->fatalError($v, 'Cannot break outside loop');
        }
        $num = $v->num;
        if ($num) {
            if ($num->value > 1) {
                $this->context->hasMultiLevelBreak = true;
                return '_brk_flag = ' . ($num->value - 1) . '; break;';
            }
        }

        return 'break;';
    }

    protected function parseContinue(Node\Stmt\Continue_ $v): string
    {
        if (!$this->context->inLoop) {
            $this->fatalError($v, 'Cannot continue outside loop');
        }
        $num = $v->num;
        if ($num) {
            if ($num->value > 1) {
                $this->context->hasMultiLevelContinue = true;
                return '_cnt_flag = ' . ($num->value - 1) . '; break;';
            }
        }
        return 'continue;';
    }

    /**
     * Emit flag-propagation checks at the end of a loop body.
     *
     * Translates multi-level break / continue into plain break / continue
     * by decrementing a counter at each loop boundary until it reaches zero.
     */
    protected function genLoopEndFlagCheck(): string
    {
        $code = '';
        $indent = $this->getIndent();
        if ($this->context->hasMultiLevelBreak) {
            $code .= "{$indent}if (_brk_flag > 0) { _brk_flag--; break; }" . PHP_EOL;
        }
        if ($this->context->hasMultiLevelContinue) {
            $code .= "{$indent}if (_cnt_flag > 0) { _cnt_flag--; if (_cnt_flag == 0) continue; else break; }" . PHP_EOL;
        }
        return $code;
    }

}

