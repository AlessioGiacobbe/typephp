<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Parser;

use PhpAot\Php\Symbol;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\NodeAbstract;

trait BinaryOpTrait
{
    protected function parseBinaryOp(NodeAbstract $left, NodeAbstract $right, string $op): string
    {
        $this->assertExprCanBeUsedAsValue($left, 'binary operand');
        $this->assertExprCanBeUsedAsValue($right, 'binary operand');

        // 运算逻辑，优先转为数字
        $leftExpr  = $this->parseOrderedBinaryOperand($left);
        $rightExpr = $this->parseOrderedBinaryOperand($right);

        $this->checkVarMustExist($left, $leftExpr);
        $this->checkVarMustExist($right, $rightExpr);

        $leftType  = $this->detectTypeOfExpr($left);
        $rightType = $this->detectTypeOfExpr($right);

        if ($leftType === self::TYPE_BIGFLOAT || $rightType === self::TYPE_BIGFLOAT) {
            // BigFloat cannot implicitly mix with BigInt or Decimal — risk of precision loss
            if ($leftType === self::TYPE_BIGINT || $rightType === self::TYPE_BIGINT) {
                $this->fatalError($left, 'Cannot mix BigFloat and BigInt implicitly. Use std::bigFloat() to convert explicitly.');
            }
            if ($leftType === self::TYPE_DECIMAL || $rightType === self::TYPE_DECIMAL) {
                $this->fatalError($left, 'Cannot mix BigFloat and Decimal implicitly. Use std::bigFloat() to convert explicitly.');
            }
            if ($leftType !== self::TYPE_BIGFLOAT) {
                $leftExpr = $this->convertBigFloatExpr($leftExpr, $leftType);
            }
            if ($rightType !== self::TYPE_BIGFLOAT) {
                $rightExpr = $this->convertBigFloatExpr($rightExpr, $rightType);
            }
            $arithOpMap = ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div'];
            $method = $arithOpMap[$op] ?? null;
            if ($method) {
                return 'php::BigFloat::' . $method . '(' . $leftExpr . ', ' . $rightExpr . ')';
            }
            $cmpOpMap = ['<' => '< 0', '>' => '> 0', '<=' => '<= 0', '>=' => '>= 0'];
            if (isset($cmpOpMap[$op])) {
                return 'php::toBool(php::BigFloat::cmp(' . $leftExpr . ', ' . $rightExpr . ') ' . $cmpOpMap[$op] . ')';
            }
        }

        if ($leftType === self::TYPE_DECIMAL || $rightType === self::TYPE_DECIMAL) {
            // BigInt and Decimal cannot implicitly mix — risk of precision loss
            if ($leftType === self::TYPE_BIGINT || $rightType === self::TYPE_BIGINT) {
                $this->fatalError($left, 'Cannot mix BigInt and Decimal implicitly. Use std::decimal() or std::bigInt() to convert explicitly.');
            }
            if ($leftType !== self::TYPE_DECIMAL) {
                $leftExpr = $this->convertDecimalExpr($leftExpr, $leftType, $left);
            }
            if ($rightType !== self::TYPE_DECIMAL) {
                $rightExpr = $this->convertDecimalExpr($rightExpr, $rightType, $right);
            }
            $arithOpMap = ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div', '%' => 'mod'];
            $method = $arithOpMap[$op] ?? null;
            if ($method) {
                return 'php::Decimal::' . $method . '(' . $leftExpr . ', ' . $rightExpr . ')';
            }
            $cmpOpMap = ['<' => '< 0', '>' => '> 0', '<=' => '<= 0', '>=' => '>= 0'];
            if (isset($cmpOpMap[$op])) {
                return 'php::toBool(php::Decimal::cmp(' . $leftExpr . ', ' . $rightExpr . ') ' . $cmpOpMap[$op] . ')';
            }
        }

        if ($leftType === self::TYPE_BIGINT || $rightType === self::TYPE_BIGINT) {
            // Bitwise shifts: right operand is shift amount, must stay as Int
            if ($op === '<<' || $op === '>>') {
                if ($leftType !== self::TYPE_BIGINT) {
                    $leftExpr = $this->convertBigIntExpr($leftExpr, $leftType);
                }
                if ($rightType === self::TYPE_BIGINT) {
                    $rightExpr = 'php::BigInt::toInt(' . $rightExpr . ')';
                } elseif ($rightType !== self::TYPE_INT) {
                    $rightExpr = $this->convertExprType($rightExpr, $rightType, self::TYPE_INT);
                }
                $method = ($op === '<<') ? 'bitShiftLeft' : 'bitShiftRight';
                return 'php::BigInt::' . $method . '(' . $leftExpr . ', ' . $rightExpr . ')';
            }
            if ($leftType !== self::TYPE_BIGINT) {
                $leftExpr = $this->convertBigIntExpr($leftExpr, $leftType);
            }
            if ($rightType !== self::TYPE_BIGINT) {
                $rightExpr = $this->convertBigIntExpr($rightExpr, $rightType);
            }
            $arithOpMap = ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div', '%' => 'mod', '&' => 'bitAnd', '|' => 'bitOr', '^' => 'bitXor'];
            $method = $arithOpMap[$op] ?? null;
            if ($method) {
                return 'php::BigInt::' . $method . '(' . $leftExpr . ', ' . $rightExpr . ')';
            }
            $cmpOpMap = ['<' => '< 0', '>' => '> 0', '<=' => '<= 0', '>=' => '>= 0'];
            if (isset($cmpOpMap[$op])) {
                return 'php::toBool(php::BigInt::cmp(' . $leftExpr . ', ' . $rightExpr . ') ' . $cmpOpMap[$op] . ')';
            }
        }

        // Any Big*-typed operand reaching here means no Big* block handled the operator
        $bigTypes = [self::TYPE_BIGFLOAT, self::TYPE_DECIMAL, self::TYPE_BIGINT];
        if (in_array($leftType, $bigTypes, true) || in_array($rightType, $bigTypes, true)) {
            $this->fatalError($left, "Operator '{$op}' is not supported for Big* numeric types");
        }

        // Only promote between native types (Int ↔ Float).  When one side is
        // php::Var, let the Variant operator handle type coercion so that
        // run-time PHP type-juggling rules are followed correctly.
        if ($leftType === self::TYPE_FLOAT && $rightType === self::TYPE_INT) {
            $rightExpr = $this->convertExprType($rightExpr, self::TYPE_FLOAT, $rightType);
        } elseif ($rightType === self::TYPE_FLOAT && $leftType === self::TYPE_INT) {
            $leftExpr = $this->convertExprType($leftExpr, $leftType, self::TYPE_FLOAT);
        }

        $this->guardLiteralDivisionByZero($right, $op);

        if ($op === '%' and !($leftType === self::TYPE_INT and $rightType === self::TYPE_INT)) {
            return 'php::fn::mod(' . $leftExpr . ', ' . $rightExpr . ')';
        }

        return '((' . $leftExpr . ') ' . $op . ' (' . $rightExpr . '))';
    }

    protected function shouldMaterializeOrderedOperand(NodeAbstract $expr): bool
    {
        if ($expr instanceof Expr\BinaryOp) {
            return $this->shouldMaterializeOrderedOperand($expr->left)
                || $this->shouldMaterializeOrderedOperand($expr->right);
        }

        return $expr instanceof Expr\FuncCall
            || $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\StaticCall
            || $expr instanceof Expr\New_
            || $expr instanceof Expr\Assign
            || $expr instanceof Expr\AssignRef
            || $expr instanceof Expr\AssignOp
            || $expr instanceof Expr\PostInc
            || $expr instanceof Expr\PostDec
            || $expr instanceof Expr\PreInc
            || $expr instanceof Expr\PreDec
            || $expr instanceof Expr\Print_
            || $expr instanceof Expr\Array_
            || $expr instanceof Expr\ArrayDimFetch
            || $expr instanceof Expr\PropertyFetch
            || $expr instanceof Expr\StaticPropertyFetch
            || $expr instanceof Expr\Ternary
            || $expr instanceof Expr\Match_
            || $expr instanceof Expr\NullsafeMethodCall
            || $expr instanceof Expr\NullsafePropertyFetch
            || $expr instanceof Expr\Clone_
            || $expr instanceof Expr\Include_
            || $expr instanceof Expr\Eval_;
    }

    protected function parseOrderedBinaryOperand(NodeAbstract $expr): float|int|string
    {
        return $this->parseOrderedOperand($expr, true);
    }

    protected function parseOrderedOperand(NodeAbstract $expr, bool $numeric): float|int|string
    {
        $this->assertExprCanBeUsedAsValue($expr, 'operand');
        if (!$this->shouldMaterializeOrderedOperand($expr)) {
            return $numeric ? $this->parseNumericIdentifier($expr) : $this->parseIdentifier($expr);
        }

        [$value, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
        $this->appendCapturedStmtLinesToContext($beforeStmts);

        $type = $this->getOrderedOperandTmpType($expr, (string) $value);
        $tmpVar = $this->addTmpVar($type);
        $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $value . ';';
        $this->appendCapturedStmtLinesToContext($afterStmts);
        return $tmpVar;
    }

    protected function getOrderedOperandTmpType(NodeAbstract $expr, string $value): string
    {
        if ($expr instanceof Expr\BinaryOp) {
            $type = $this->detectTypeOfExpr($expr);
            return in_array($type, [self::TYPE_BIGINT, self::TYPE_DECIMAL, self::TYPE_BIGFLOAT], true) ? $type : self::TYPE_VAR;
        }

        if (
            $expr instanceof Expr\FuncCall
            || $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\StaticCall
        ) {
            $type = $this->detectTypeOfExpr($expr);
            return in_array($type, [self::TYPE_BIGINT, self::TYPE_DECIMAL, self::TYPE_BIGFLOAT], true) ? $type : self::TYPE_VAR;
        }

        if ($expr instanceof Expr\PropertyFetch) {
            $nativePropertyVar = $this->getNativePropertyVar($expr);
            if ($nativePropertyVar !== null && $nativePropertyVar === $value) {
                $info = $this->getObjectPropInfoByVar($nativePropertyVar);
                if ($info !== null) {
                    return $info['type'];
                }
                $def = $this->getNativePropertyDef($expr);
                if ($def && $this->isNativePropertyTypedValue($expr)) {
                    return $def->type;
                }
            }
            return self::TYPE_VAR;
        }

        if ($expr instanceof Expr\StaticPropertyFetch) {
            $def = $this->getNativePropertyDef($expr);
            if ($def && $this->isNativePropertyTypedValue($expr)) {
                return $def->type;
            }
            return self::TYPE_VAR;
        }

        if ($expr instanceof Expr\ArrayDimFetch) {
            return self::TYPE_VAR;
        }

        $type = $this->detectTypeOfExpr($expr);
        return $type;
    }

    protected function appendCapturedStmtLinesToContext(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            $this->context->beforeStmtLines[] = $stmt;
        }
    }

    protected function parseBinaryOpPlus(Expr\BinaryOp\Plus $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '+');
    }

    protected function parseBinaryOpMul(Expr\BinaryOp\Mul $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '*');
    }

    protected function parseBinaryOpConcat(Expr\BinaryOp\Concat $expr): string
    {
        $items = [];
        $this->flattenConcatExpr($expr, $items);

        $argList = [];
        foreach ($items as $item) {
            $type = $this->detectTypeOfExpr($item);
            if ($type === self::TYPE_VOID) {
                $this->fatalError($expr, 'Cannot concat void');
            }
            $argList[] = $this->convertExprToStringByType($this->parseExpr($item), $type);
        }

        return Symbol::concat() . '(' . Symbol::argList() . '{' . implode(', ', $argList) . '})';
    }

    private function flattenConcatExpr(NodeAbstract $expr, array &$items): void
    {
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $this->flattenConcatExpr($expr->left, $items);
            $this->flattenConcatExpr($expr->right, $items);
        } else {
            $items[] = $expr;
        }
    }

    protected function parseBinaryOpSmaller(Expr\BinaryOp\Smaller $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '<'));
    }

    protected function parseBinaryOpShiftLeft(Expr\BinaryOp\ShiftLeft $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '<<');
    }

    protected function parseBinaryOpShiftRight(Expr\BinaryOp\ShiftRight $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '>>');
    }

    protected function parseBinaryOpMod(Expr\BinaryOp\Mod $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '%');
    }

    protected function parseBinaryOpGreater(Expr\BinaryOp\Greater $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '>'));
    }

    protected function parseBinaryOpPow(Expr\BinaryOp\Pow $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->left, 'binary operand');
        $this->assertExprCanBeUsedAsValue($expr->right, 'binary operand');
        $leftType = $this->detectTypeOfExpr($expr->left);
        if ($leftType === self::TYPE_BIGINT) {
            $leftExpr = $this->parseOrderedOperand($expr->left, false);
            $rightExpr = $this->parseOrderedOperand($expr->right, false);
            $rightType = $this->detectTypeOfExpr($expr->right);
            if ($rightType !== self::TYPE_BIGINT) {
                $rightExpr = $this->convertBigIntExpr($rightExpr, $rightType);
            }
            return 'php::BigInt::pow(' . $leftExpr . ', ' . $rightExpr . ')';
        }
        $left  = $this->parseOrderedOperand($expr->left, false);
        $right = $this->parseOrderedOperand($expr->right, false);
        return 'php::fn::pow(' . $left . ', ' . $right . ')';
    }

    protected function parseBinaryOpBitwiseAnd(Expr\BinaryOp\BitwiseAnd $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '&');
    }

    protected function parseBinaryOpBitwiseOr(Expr\BinaryOp\BitwiseOr $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '|');
    }

    protected function parseBinaryOpBitwiseXor(Expr\BinaryOp\BitwiseXor $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '^');
    }

    protected function parseCompareExpr(NodeAbstract $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr, 'comparison operand');
        // PHPX 与 bool 值比较会出现重载错误，所以需要转换成 bool 值
        if ($this->isScalarBool($expr)) {
            return $this->getBoolValue($expr);
        }
        return $this->parseOrderedOperand($expr, false);
    }

    protected function parseBinaryOpEqual(Expr\BinaryOp\Equal $expr): string
    {
        return $this->genBigNumericCmp($expr, ' == 0')
            ?? 'php::equals(' . $this->parseCompareExpr($expr->left) . ', ' . $this->parseCompareExpr($expr->right) . ')';
    }

    protected function parseBinaryOpNotEqual(Expr\BinaryOp\NotEqual $expr): string
    {
        return $this->genBigNumericCmp($expr, ' != 0')
            ?? '!php::equals(' . $this->parseCompareExpr($expr->left) . ', ' . $this->parseCompareExpr($expr->right) . ')';
    }

    protected function parseBinaryOpIdentical(Expr\BinaryOp $expr): string
    {
        $left  = $this->parseCompareExpr($expr->left);
        $right = $this->parseCompareExpr($expr->right);
        if ($right === 'nullptr') {
            return $left . '.isNull()';
        }
        if ($optimized = $this->optimizeIdenticalOp($expr->left, $expr->right, $left, $right)) {
            return $optimized;
        }
        return 'php::same(' . $left . ', ' . $right . ')';
    }

    /**
     * Use compile-time type info to optimize === and !== .
     * When both sides are the same narrowed primitive type, emit direct C++ == .
     * When both are narrowed but different types, === is always false.
     */
    private function optimizeIdenticalOp(NodeAbstract $astLeft, NodeAbstract $astRight, string $cppLeft, string $cppRight): ?string
    {
        $primitiveTypes = [self::TYPE_INT, self::TYPE_FLOAT, self::TYPE_BOOL];
        $leftType  = $this->detectTypeOfExpr($astLeft);
        $rightType = $this->detectTypeOfExpr($astRight);

        if ($leftType === null || $rightType === null) {
            return null;
        }
        if (!in_array($leftType, $primitiveTypes, true) || !in_array($rightType, $primitiveTypes, true)) {
            return null;
        }
        if ($leftType === $rightType) {
            return $cppLeft . ' == ' . $cppRight;
        }
        return 'false';
    }

    protected function parseBinaryOpLogicalAnd(Expr\BinaryOp\LogicalAnd|Expr\BinaryOp\BooleanAnd $expr): string
    {
        return $this->parseShortCircuitLogicalOp($expr->left, $expr->right, '&&');
    }

    protected function parseBinaryOpLogicalOr(Expr\BinaryOp\LogicalOr|Expr\BinaryOp\BooleanOr $expr): string
    {
        return $this->parseShortCircuitLogicalOp($expr->left, $expr->right, '||');
    }

    protected function parseShortCircuitLogicalOp(NodeAbstract $left, NodeAbstract $right, string $op): string
    {
        $this->assertExprCanBeUsedAsCondition($left, 'logical operand');
        $this->assertExprCanBeUsedAsCondition($right, 'logical operand');

        $leftExpr = $this->parseNumericIdentifier($left);
        $this->checkVarMustExist($left, $leftExpr);

        $rightBeforeStmtCount = count($this->context->beforeStmtLines);
        $rightAfterStmtCount = count($this->context->afterStmtLines);
        $rightExpr = $this->parseNumericIdentifier($right);
        $rightBeforeStmts = array_slice($this->context->beforeStmtLines, $rightBeforeStmtCount);
        $rightAfterStmts = array_slice($this->context->afterStmtLines, $rightAfterStmtCount);
        $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $rightBeforeStmtCount);
        $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $rightAfterStmtCount);
        $this->checkVarMustExist($right, $rightExpr);

        $leftBool = $this->convertBoolExpr((string) $leftExpr);
        if (!$rightBeforeStmts && !$rightAfterStmts) {
            return '(' . $leftBool . ' ' . $op . ' ' . $this->convertBoolExpr((string) $rightExpr) . ')';
        }

        $shortCircuitValue = $op === '&&' ? 'false' : 'true';
        $rightCondition = $op === '&&' ? $leftBool : '!(' . $leftBool . ')';

        $code = '[&]() -> bool {';
        $code .= $this->getIndent() . 'if (' . $rightCondition . ') {';
        $code .= $this->formatCapturedStmtLines($rightBeforeStmts);
        if ($rightAfterStmts) {
            $rightTmpVar = $this->addTmpVar(self::TYPE_VAR);
            $code .= $this->getIndent() . $rightTmpVar . ' = ' . $rightExpr . ';';
            $code .= $this->formatCapturedStmtLines($rightAfterStmts);
            $rightExpr = $rightTmpVar;
        }
        $code .= $this->getIndent() . 'return ' . $this->convertBoolExpr((string) $rightExpr) . ';';
        $code .= $this->getIndent() . '}';
        $code .= $this->getIndent() . 'return ' . $shortCircuitValue . ';';
        $code .= $this->getIndent() . '}()';

        return $code;
    }

    protected function parseBinaryOpLogicalXor(Expr\BinaryOp\LogicalXor $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '^'));
    }

    protected function parseBinaryOpSmallerOrEqual(Expr\BinaryOp\SmallerOrEqual $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '<='));
    }

    protected function parseBinaryOpGreaterOrEqual(Expr\BinaryOp\GreaterOrEqual $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '>='));
    }

    protected function parseBinaryOpSpaceship(Expr\BinaryOp\Spaceship $expr): string
    {
        return $this->genBigNumericCmp($expr)
            ?? 'php::compare(' . $this->parseOrderedOperand($expr->left, false) . ', ' . $this->parseOrderedOperand($expr->right, false) . ')';
    }

    protected function genBigNumericCmp(Expr\BinaryOp $expr, string $suffix = ''): ?string
    {
        $leftType = $this->detectTypeOfExpr($expr->left);
        $rightType = $this->detectTypeOfExpr($expr->right);

        if ($leftType === self::TYPE_BIGFLOAT || $rightType === self::TYPE_BIGFLOAT) {
            $leftExpr = $this->parseOrderedOperand($expr->left, false);
            $rightExpr = $this->parseOrderedOperand($expr->right, false);
            if ($leftType !== self::TYPE_BIGFLOAT) {
                $leftExpr = $this->convertBigFloatExpr($leftExpr, $leftType);
            }
            if ($rightType !== self::TYPE_BIGFLOAT) {
                $rightExpr = $this->convertBigFloatExpr($rightExpr, $rightType);
            }
            return 'php::BigFloat::cmp(' . $leftExpr . ', ' . $rightExpr . ')' . $suffix;
        }
        if ($leftType === self::TYPE_BIGINT || $rightType === self::TYPE_BIGINT) {
            $leftExpr = $this->parseOrderedOperand($expr->left, false);
            $rightExpr = $this->parseOrderedOperand($expr->right, false);
            if ($leftType !== self::TYPE_BIGINT) {
                $leftExpr = $this->convertBigIntExpr($leftExpr, $leftType);
            }
            if ($rightType !== self::TYPE_BIGINT) {
                $rightExpr = $this->convertBigIntExpr($rightExpr, $rightType);
            }
            return 'php::BigInt::cmp(' . $leftExpr . ', ' . $rightExpr . ')' . $suffix;
        }
        if ($leftType === self::TYPE_DECIMAL || $rightType === self::TYPE_DECIMAL) {
            $leftExpr = $this->parseOrderedOperand($expr->left, false);
            $rightExpr = $this->parseOrderedOperand($expr->right, false);
            if ($leftType !== self::TYPE_DECIMAL) {
                $leftExpr = $this->convertDecimalExpr($leftExpr, $leftType, $expr->left);
            }
            if ($rightType !== self::TYPE_DECIMAL) {
                $rightExpr = $this->convertDecimalExpr($rightExpr, $rightType, $expr->right);
            }
            return 'php::Decimal::cmp(' . $leftExpr . ', ' . $rightExpr . ')' . $suffix;
        }

        return null;
    }

    protected function parseBinaryOpCoalesce(Expr\BinaryOp\Coalesce $expr): string
    {
        return $this->parseValueSelection($expr, $expr->left, $expr->right, self::OP_ISSET);
    }

    protected function parseBinaryOpNotIdentical(Expr\BinaryOp $expr): string
    {
        return '!(' . $this->parseBinaryOpIdentical($expr) . ')';
    }

    protected function parseBinaryOpDiv(Expr\BinaryOp\Div $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '/');
    }

    protected function guardLiteralDivisionByZero(NodeAbstract $right, string $op): void
    {
        if (($op === '/' or $op === '%' or $op === '/=' or $op === '%=') and $this->isZeroLiteral($right)) {
            $this->fatalError($right, 'Cannot divide or modulo by zero');
        }
    }

    protected function parseBinaryOpMinus(Expr\BinaryOp\Minus $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '-');
    }

}
