<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Parser;

use PhpParser\Node;
use PhpParser\NodeAbstract;

trait TypeConversionTrait
{
    protected function convertExprToStringByType(string $expr, $type): string
    {
        if ($type === self::TYPE_BIGINT) {
            return 'php::BigInt::toString(' . $expr . ')';
        }
        if ($type === self::TYPE_BIGFLOAT) {
            return 'php::BigFloat::toString(' . $expr . ')';
        }
        if ($type === self::TYPE_DECIMAL) {
            return 'php::Decimal::toString(' . $expr . ')';
        }
        return $this->convertStringExpr($expr);
    }

    protected function convertIntExpr(string $expr): string
    {
        if (!$this->isClosedExpr($expr, 'php::toInt')) {
            return 'php::toInt(' . $expr . ')';
        }

        return $expr;
    }

    protected function convertFloatExpr(string $expr): string
    {
        if (!$this->isClosedExpr($expr, 'php::toFloat')) {
            return 'php::toFloat(' . $expr . ')';
        }

        return $expr;
    }

    protected function convertDecimalExpr(string $expr, string $fromType = '', ?NodeAbstract $node = null): string
    {
        if ($fromType === self::TYPE_FLOAT) {
            if ($node instanceof Node\Scalar\Float_) {
                $rawValue = $node->getAttribute('rawValue');
                $clean = $rawValue !== null ? $this->stripNumericUnderscores($rawValue) : (string) $node->value;
                                return 'php::toDecimal(' . $this->getLiteralString($clean) . ')';
            }
            $this->fatalError($node, 'Cannot convert float expression to Decimal, use a literal value or string instead');
        }
        if ($fromType === self::TYPE_STR) {
            if ($node instanceof Node\Scalar\String_) {
                return 'php::toDecimal(' . $this->getLiteralString($node->value) . ')';
            }
            return 'php::toDecimal(php::toString(' . $expr . '))';
        }
        if ($fromType === self::TYPE_INT) {
            return 'php::toDecimal(php::toString(' . $expr . '))';
        }
        if ($fromType === self::TYPE_BIGINT) {
            return 'php::toDecimal(php::BigInt::toString(' . $expr . '))';
        }
        return $expr;
    }

    protected function convertBigIntExpr(string $expr, string $fromType = ''): string
    {
        if ($fromType === self::TYPE_INT) {
            return 'php::toBigInt(' . $expr . ')';
        }
        if ($fromType === self::TYPE_FLOAT) {
            $this->error('Cannot convert float to BigInt, use string or int instead');
        }
        if ($fromType === self::TYPE_STR) {
            return 'php::toBigInt(php::toString(' . $expr . '))';
        }
        return $expr;
    }

    protected function convertBigFloatExpr(string $expr, string $fromType = ''): string
    {
        if ($fromType === self::TYPE_INT) {
            return 'php::toBigFloat(' . $expr . ')';
        }
        if ($fromType === self::TYPE_FLOAT) {
            return 'php::toBigFloat(' . $expr . ')';
        }
        if ($fromType === self::TYPE_STR) {
            return 'php::toBigFloat(php::toString(' . $expr . '))';
        }
        if ($fromType === self::TYPE_BIGINT) {
            return 'php::BigFloat::newInstance(php::BigInt::toString(' . $expr . '))';
        }
        if ($fromType === self::TYPE_DECIMAL) {
            return 'php::BigFloat::newInstance(php::Decimal::toString(' . $expr . '))';
        }
        return $expr;
    }

    protected function convertStringExpr(string $expr): string
    {
        if (!$this->isClosedExpr($expr, 'php::toString')) {
            return 'php::toString(' . $expr . ')';
        }

        return $expr;
    }

    protected function convertObjectExpr(string $expr, string $class = ''): string
    {
        if (!$this->isClosedExpr($expr, 'php::toObject')) {
            if ($class === '') {
                return 'php::toObject(' . $expr . ')';
            }
            return 'php::toObject(' . $expr . ', ' . $class . ')';
        }

        return $expr;
    }

    protected function convertArrayExpr(string $expr): string
    {
        if (!$this->isClosedExpr($expr, 'php::toArray')) {
            return 'php::toArray(' . $expr . ')';
        }

        return $expr;
    }

    protected function convertBoolExpr(string $expr): string
    {
        if (!$this->isClosedExpr($expr, 'php::toBool')) {
            return 'php::toBool(' . $expr . ')';
        }

        return $expr;
    }

    protected function convertExprType(string $expr, $leftType, $rightType): string
    {
        if ($leftType === self::TYPE_FLOAT or $rightType === self::TYPE_FLOAT) {
            return $this->convertFloatExpr($expr);
        }
        if ($leftType === self::TYPE_INT or $rightType === self::TYPE_INT) {
            return $this->convertIntExpr($expr);
        }
        if ($leftType === self::TYPE_BOOL or $rightType === self::TYPE_BOOL) {
            return $this->convertBoolExpr($expr);
        }

        return $expr;
    }

    protected function getNativeType(string $type): string
    {
        if ($type === self::TYPE_INT && $this->bigintTypes) {
            return self::TYPE_BIGINT;
        }
        if ($type === self::TYPE_FLOAT && $this->decimalTypes) {
            return self::TYPE_DECIMAL;
        }
        return $this->nativeTypes ? $type : self::TYPE_VAR;
    }

    protected function convertExprFromType(string $type, string $expr): string
    {
        if ($type === self::TYPE_FLOAT) {
            return $this->convertFloatExpr($expr);
        }
        if ($type === self::TYPE_INT) {
            return $this->convertIntExpr($expr);
        }
        if ($type === self::TYPE_BOOL) {
            return $this->convertBoolExpr($expr);
        }
        if ($type === self::TYPE_STR) {
            return $this->convertStringExpr($expr);
        }
        if ($type === self::TYPE_ARRAY) {
            return $this->convertArrayExpr($expr);
        }
        if ($type === self::TYPE_OBJECT) {
            return $this->convertObjectExpr($expr);
        }

        return $expr;
    }

    protected function convertVarType($var, $expr): string
    {
        if ($this->hasVar($var)) {
            return $this->convertExprFromType($this->getVarType($var), $expr);
        }

        return $expr;
    }

    protected function convertToRef(NodeAbstract $expr): string
    {
        $this->checkLeftValue($expr);
        $var = $this->parseIdentifier($expr);
        if ($this->isVarExpr($expr) and $this->isNativeTypeVar($var)) {
            $this->context->localVars[$var] = self::TYPE_VAR;
        }
        return $this->parseIdentifier($expr) . '.toReference()';
    }

}
