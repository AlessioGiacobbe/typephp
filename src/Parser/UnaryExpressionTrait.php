<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node\Expr;

trait UnaryExpressionTrait
{
    protected function parseBitwiseNot(Expr\BitwiseNot $expr): string
    {
        $type = $this->detectTypeOfExpr($expr->expr);
        $this->assertExprCanBeUsedAsValue($expr->expr, 'bitwise operand');
        if ($type === Type::BIGINT) {
            return 'php::BigInt::bitNot(' . $this->parseExpr($expr->expr) . ')';
        }
        $var = $this->parseIdentifier($expr->expr);
        return '~' . $this->convertIntExpr($var);
    }

    protected function parseBooleanNot(Expr\BooleanNot $expr): string
    {
        $this->assertExprCanBeUsedAsCondition($expr->expr, 'boolean operand');
        return '!(' . $this->parseExprAsValue($expr->expr) . ')';
    }

    protected function parseCastInt(Expr\Cast\Int_ $node): string
    {
        $this->assertExprCanBeUsedAsValue($node->expr, 'cast operand');
        return $this->convertIntExpr(
            $this->parseExprAsValue($node->expr),
            $this->detectTypeOfExpr($node->expr)
        );
    }

    protected function parseCastString(Expr\Cast\String_ $node): string
    {
        $this->assertExprCanBeUsedAsValue($node->expr, 'cast operand');
        return $this->convertExprToStringByType(
            $this->parseExprAsValue($node->expr),
            $this->detectTypeOfExpr($node->expr)
        );
    }

    protected function parseCastBool(Expr\Cast\Bool_ $node): string
    {
        $this->assertExprCanBeUsedAsValue($node->expr, 'cast operand');
        return $this->convertBoolExpr(
            $this->parseExprAsValue($node->expr),
            $this->detectTypeOfExpr($node->expr)
        );
    }

    protected function parseCastObject(Expr\Cast\Object_ $node): string
    {
        $this->assertExprCanBeUsedAsValue($node->expr, 'cast operand');
        return $this->convertObjectExpr($this->parseExprAsValue($node->expr));
    }

    protected function parseUnaryMinus(Expr\UnaryMinus $expr): string
    {
        $type = $this->detectTypeOfExpr($expr->expr);
        $this->assertExprCanBeUsedAsValue($expr->expr, 'unary operand');
        if ($type === Type::BIGFLOAT) {
            return 'php::BigFloat::neg(' . $this->parseExprAsValue($expr->expr) . ')';
        }
        if ($type === Type::BIGINT) {
            return 'php::BigInt::neg(' . $this->parseExprAsValue($expr->expr) . ')';
        }
        if ($type === Type::DECIMAL) {
            return 'php::Decimal::neg(' . $this->parseExprAsValue($expr->expr) . ')';
        }
        $code = $this->parseExprAsValue($expr->expr);

        return '-' . $code;
    }

    protected function parseUnaryPlus(Expr\UnaryPlus $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->expr, 'unary operand');
        return $this->parseExprAsValue($expr->expr);
    }
}
