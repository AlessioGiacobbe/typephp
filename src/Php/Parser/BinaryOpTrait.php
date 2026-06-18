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
        // 运算逻辑，优先转为数字
        $leftExpr  = $this->parseNumericIdentifier($left);
        $rightExpr = $this->parseNumericIdentifier($right);

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
        $left = $this->parseExpr($expr->left);
        $right = $this->parseExpr($expr->right);

        $leftType = $this->detectTypeOfExpr($expr->left);
        $rightType = $this->detectTypeOfExpr($expr->right);
        if ($leftType === self::TYPE_VOID or $rightType === self::TYPE_VOID) {
            $this->fatalError($expr, 'Cannot concat void');
        }
        return Symbol::concat() . '(' . $this->convertExprToStringByType($left, $leftType) . ', ' . $this->convertExprToStringByType($right, $rightType) . ')';
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
        $leftType = $this->detectTypeOfExpr($expr->left);
        if ($leftType === self::TYPE_BIGINT) {
                        $leftExpr = $this->parseExpr($expr->left);
            $rightExpr = $this->parseExpr($expr->right);
            $rightType = $this->detectTypeOfExpr($expr->right);
            if ($rightType !== self::TYPE_BIGINT) {
                $rightExpr = $this->convertBigIntExpr($rightExpr, $rightType);
            }
            return 'php::BigInt::pow(' . $leftExpr . ', ' . $rightExpr . ')';
        }
        $left  = $this->parseIdentifier($expr->left);
        $right = $this->parseIdentifier($expr->right);
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
        // PHPX 与 bool 值比较会出现重载错误，所以需要转换成 bool 值
        if ($this->isScalarBool($expr)) {
            return $this->getBoolValue($expr);
        }
        return $this->parseIdentifier($expr);
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
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '&&'));
    }

    protected function parseBinaryOpLogicalOr(Expr\BinaryOp\LogicalOr|Expr\BinaryOp\BooleanOr $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '||'));
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
            ?? 'php::compare(' . $this->parseIdentifier($expr->left) . ', ' . $this->parseIdentifier($expr->right) . ')';
    }

    protected function genBigNumericCmp(Expr\BinaryOp $expr, string $suffix = ''): ?string
    {
        $leftType = $this->detectTypeOfExpr($expr->left);
        $rightType = $this->detectTypeOfExpr($expr->right);

        if ($leftType === self::TYPE_BIGFLOAT || $rightType === self::TYPE_BIGFLOAT) {
            $leftExpr = $this->parseExpr($expr->left);
            $rightExpr = $this->parseExpr($expr->right);
            if ($leftType !== self::TYPE_BIGFLOAT) {
                $leftExpr = $this->convertBigFloatExpr($leftExpr, $leftType);
            }
            if ($rightType !== self::TYPE_BIGFLOAT) {
                $rightExpr = $this->convertBigFloatExpr($rightExpr, $rightType);
            }
            return 'php::BigFloat::cmp(' . $leftExpr . ', ' . $rightExpr . ')' . $suffix;
        }
        if ($leftType === self::TYPE_BIGINT || $rightType === self::TYPE_BIGINT) {
            $leftExpr = $this->parseExpr($expr->left);
            $rightExpr = $this->parseExpr($expr->right);
            if ($leftType !== self::TYPE_BIGINT) {
                $leftExpr = $this->convertBigIntExpr($leftExpr, $leftType);
            }
            if ($rightType !== self::TYPE_BIGINT) {
                $rightExpr = $this->convertBigIntExpr($rightExpr, $rightType);
            }
            return 'php::BigInt::cmp(' . $leftExpr . ', ' . $rightExpr . ')' . $suffix;
        }
        if ($leftType === self::TYPE_DECIMAL || $rightType === self::TYPE_DECIMAL) {
            $leftExpr = $this->parseExpr($expr->left);
            $rightExpr = $this->parseExpr($expr->right);
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
