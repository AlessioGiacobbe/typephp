<?php
/**
 * This file is part of TypePHP.
 *
 * Lowers if, elseif, and else statement chains.
 */

namespace TypePhp\Parser;

use PhpParser\Node;

trait ConditionalControlTrait
{
    protected function parseIf(Node\Stmt\If_ $v): string
    {
        $arms = [[$v->cond, $v->stmts]];
        foreach ($v->elseifs as $elseif) {
            $arms[] = [$elseif->cond, $elseif->stmts];
        }

        return $this->parseBeforeStmtLines() . PHP_EOL . $this->parseIfChain($arms, $v->else, 0) . PHP_EOL;
    }

    protected function parseIfChain(array $arms, ?Node\Stmt\Else_ $else, int $index): string
    {
        if (!isset($arms[$index])) {
            if (!$else || $this->isEmptyStmtList($else->stmts)) {
                return '';
            }
            return $this->parseBlockStmts($else->stmts);
        }

        [$cond, $stmts] = $arms[$index];
        $code = $this->genConditionWithCapturedStmts($cond, 'if ');
        $code .= $this->parseBlockStmts($stmts);
        $tail = $this->parseIfChain($arms, $else, $index + 1);
        if ($tail !== '') {
            $code .= $this->getIndent() . '} else {' . PHP_EOL;
            $code .= $tail;
        }
        $code .= $this->getIndent() . '}';
        return $code;
    }

    protected function isEmptyStmtList(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if (!$stmt instanceof Node\Stmt\Nop) {
                return false;
            }
        }
        return true;
    }

    /**
     * 逻辑比较的运算，必须返回 bool 类型.
     */
}

