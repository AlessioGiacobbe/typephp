<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use PhpParser\Node;
use PhpParser\NodeAbstract;

trait TypeConversionTrait
{
    protected function convertExprToStringByType(string $expr, $type): string
    {
        if ($type === Type::STR) {
            return $expr;
        }
        if ($type === Type::BIGINT) {
            return 'php::BigInt::toString(' . $expr . ')';
        }
        if ($type === Type::BIGFLOAT) {
            return 'php::BigFloat::toString(' . $expr . ')';
        }
        if ($type === Type::DECIMAL) {
            return 'php::Decimal::toString(' . $expr . ')';
        }
        return $this->convertStringExpr($expr);
    }

    protected function convertIntExpr(string $expr, string $fromType = ''): string
    {
        $bigConversion = match ($fromType) {
            Type::BIGINT => 'php::BigInt::toInt',
            Type::BIGFLOAT => 'php::BigFloat::toInt',
            Type::DECIMAL => 'php::Decimal::toInt',
            default => null,
        };
        if ($bigConversion !== null) {
            return $bigConversion . '(' . $expr . ')';
        }
        if (!$this->isClosedExpr($expr, 'php::toInt')) {
            return 'php::toInt(' . $expr . ')';
        }

        return $expr;
    }

    protected function convertFloatExpr(string $expr, string $fromType = ''): string
    {
        $bigConversion = match ($fromType) {
            Type::BIGINT => 'php::BigInt::toFloat',
            Type::BIGFLOAT => 'php::BigFloat::toFloat',
            Type::DECIMAL => 'php::Decimal::toFloat',
            default => null,
        };
        if ($bigConversion !== null) {
            return $bigConversion . '(' . $expr . ')';
        }
        if (!$this->isClosedExpr($expr, 'php::toFloat')) {
            return 'php::toFloat(' . $expr . ')';
        }

        return $expr;
    }

    protected function convertDecimalExpr(string $expr, string $fromType = '', ?NodeAbstract $node = null): string
    {
        if ($fromType === Type::FLOAT) {
            if ($node instanceof Node\Scalar\Float_) {
                $rawValue = $node->getAttribute('rawValue');
                $clean = $rawValue !== null ? $this->stripNumericUnderscores($rawValue) : (string) $node->value;
                                return 'php::toDecimal(' . $this->getLiteralString($clean) . ')';
            }
            $this->fatalError($node, 'Cannot convert float expression to Decimal, use a literal value or string instead');
        }
        if ($fromType === Type::STR) {
            if ($node instanceof Node\Scalar\String_) {
                return 'php::toDecimal(' . $this->getLiteralString($node->value) . ')';
            }
            return 'php::toDecimal(php::toString(' . $expr . '))';
        }
        if ($fromType === Type::INT) {
            return 'php::toDecimal(php::toString(' . $expr . '))';
        }
        if ($fromType === Type::BIGINT) {
            return 'php::toDecimal(php::BigInt::toString(' . $expr . '))';
        }
        return $expr;
    }

    protected function convertBigIntExpr(string $expr, string $fromType = ''): string
    {
        if ($fromType === Type::INT) {
            return 'php::toBigInt(' . $expr . ')';
        }
        if ($fromType === Type::FLOAT) {
            $this->error('Cannot convert float to BigInt, use string or int instead');
        }
        if ($fromType === Type::STR) {
            return 'php::toBigInt(php::toString(' . $expr . '))';
        }
        return $expr;
    }

    protected function convertBigFloatExpr(string $expr, string $fromType = ''): string
    {
        if ($fromType === Type::INT) {
            return 'php::toBigFloat(' . $expr . ')';
        }
        if ($fromType === Type::FLOAT) {
            return 'php::toBigFloat(' . $expr . ')';
        }
        if ($fromType === Type::STR) {
            return 'php::toBigFloat(php::toString(' . $expr . '))';
        }
        if ($fromType === Type::BIGINT) {
            return 'php::BigFloat::newInstance(php::BigInt::toString(' . $expr . '))';
        }
        if ($fromType === Type::DECIMAL) {
            return 'php::BigFloat::newInstance(php::Decimal::toString(' . $expr . '))';
        }
        return $expr;
    }

    protected function convertStringExpr(string $expr): string
    {
        if (preg_match('/^_literal_strings\[\d+\]$/', $expr) === 1) {
            return $expr;
        }
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

    protected function convertBoolExpr(string $expr, string $fromType = ''): string
    {
        if ($fromType === Type::BOOL) {
            return $expr;
        }
        $bigConversion = match ($fromType) {
            Type::BIGINT => 'php::BigInt::toBool',
            Type::BIGFLOAT => 'php::BigFloat::toBool',
            Type::DECIMAL => 'php::Decimal::toBool',
            default => null,
        };
        if ($bigConversion !== null) {
            return $bigConversion . '(' . $expr . ')';
        }
        if (!$this->isClosedExpr($expr, 'php::toBool')) {
            return 'php::toBool(' . $expr . ')';
        }

        return $expr;
    }

    protected function convertConditionExpr(NodeAbstract $node, string $expr): string
    {
        $type = $this->detectTypeOfExpr($node);
        if (in_array($type, [Type::BIGINT, Type::BIGFLOAT, Type::DECIMAL], true)) {
            return $this->convertBoolExpr($expr, $type);
        }
        return $expr;
    }

    protected function convertExprType(string $expr, $leftType, $rightType): string
    {
        if ($leftType === Type::FLOAT or $rightType === Type::FLOAT) {
            return $this->convertFloatExpr($expr);
        }
        if ($leftType === Type::INT or $rightType === Type::INT) {
            return $this->convertIntExpr($expr);
        }
        if ($leftType === Type::BOOL or $rightType === Type::BOOL) {
            return $this->convertBoolExpr($expr);
        }

        return $expr;
    }

    protected function getNativeType(string $type): string
    {
        if ($type === Type::INT && $this->bigintTypes) {
            return Type::BIGINT;
        }
        if ($type === Type::FLOAT && $this->decimalTypes) {
            return Type::DECIMAL;
        }
        return $this->nativeTypes ? $type : Type::VAR;
    }

    protected function convertExprFromType(string $type, string $expr): string
    {
        if ($type === Type::FLOAT) {
            return $this->convertFloatExpr($expr);
        }
        if ($type === Type::INT) {
            return $this->convertIntExpr($expr);
        }
        if ($type === Type::BOOL) {
            return $this->convertBoolExpr($expr);
        }
        if ($type === Type::STR) {
            return $this->convertStringExpr($expr);
        }
        if ($type === Type::ARRAY) {
            return $this->convertArrayExpr($expr);
        }
        if ($type === Type::OBJECT) {
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
            $this->context->localVars[$var] = Type::VAR;
        }
        return $this->parseIdentifier($expr) . '.toReference()';
    }

}
