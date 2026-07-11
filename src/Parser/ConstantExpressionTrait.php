<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Parser;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\MagicConst;
use TypePhp\Generator\Symbol;

trait ConstantExpressionTrait
{
    protected function parseConstFetch(Expr\ConstFetch $expr, bool $scalar = false): string
    {
        if ($expr->name->getType() != 'Name' and !($expr->name instanceof Node\Name\FullyQualified)) {
            abort($expr);
        }
        $name = $this->parseIdentifier($expr->name);
        $name = ltrim($name, '\\');
        if ($this->isNameExpr($expr->name) and $this->hasConstant($name)) {
            return $this->getConstant($name);
        }
        if ($this->namespace and $this->isNameExpr($expr->name) and !$expr->name instanceof Node\Name\FullyQualified) {
            $nsName = $this->namespace . '\\' . $name;
            if ($this->hasConstant($nsName)) {
                return $this->getConstant($nsName);
            }
        }
        if ($this->isNameExpr($expr->name) and isset($this->useConstants[$name])) {
            $importedName = $this->useConstants[$name];
            if ($this->hasConstant($importedName)) {
                return $this->getConstant($importedName);
            }
        }
        if (strcasecmp($name, 'null') === 0) {
            return self::VALUE_NULL;
        }
        if (strcasecmp($name, 'true') === 0) {
            return 'true';
        }
        if (strcasecmp($name, 'false') === 0) {
            return 'false';
        }
        if ($name === 'PHP_EOL') {
            return '"' . $this->escapeString(PHP_EOL) . '"';
        }
        if ($this->isInternalScalarConstant($name)) {
            return $this->getInternalScalarConstantValue($name);
        }
        if ($scalar) {
            return constant($expr->name);
        }
        if ($this->isNameExpr($expr->name)) {
            if (str_contains($name, '::')) {
                $ns = explode('::', $name)[0];
                $fullName = $this->getNamespacedClassName($ns[0]);
                $ce = $this->getClassEntryPtr($fullName);
                return Symbol::constant() . '(' . $ce . ', ' . $this->getLiteralString($ns[1]) . ')';
            }
            if ($this->isInternalConstant($name)) {
                return Symbol::constant() . '(' . $this->getLiteralString($name) . ')';
            }
            if (isset($this->useAliases[$name])) {
                $name = $this->useAliases[$name];
            } elseif (isset($this->useConstants[$name])) {
                $name = $this->useConstants[$name];
            } else {
                $fullName = $this->getNamespacedClassName($name);
                if ($fullName) {
                    $name = $fullName;
                }
            }
            return Symbol::constant() . '(nullptr, ' . $this->getLiteralString($name) . ')';
        }
        return Symbol::constant() . '("' . $this->escapeString($name) . '")';
    }

    protected function parseMagicConst(MagicConst $expr): string
    {
        $class = ($this->namespace ? $this->namespace . '\\' : '') . $this->class;
        $function = ($this->namespace ? $this->namespace . '\\' : '') . $this->function;
        switch ($expr->getType()) {
            case 'Scalar_MagicConst_Dir':
                return '"' . $this->escapeString($this->dir) . '"';
            case 'Scalar_MagicConst_File':
                return '"' . $this->escapeString($this->file) . '"';
            case 'Scalar_MagicConst_Line':
                return (string) $expr->getStartLine();
            case 'Scalar_MagicConst_Function':
                return '"' . $this->escapeString($function) . '"';
            case 'Scalar_MagicConst_Class':
                if (!$this->classDef) {
                    $this->fatalError($expr, 'The magic constant `__CLASS__` is not allowed in global scope');
                }
                if ($this->classDef->trait) {
                    return Symbol::getCalledClass();
                }
                return '"' . $this->escapeString($class) . '"';
            case 'Scalar_MagicConst_Trait':
                if (!$this->classDef or !$this->classDef->trait) {
                    $this->fatalError($expr, 'The magic constant `__TRAIT__` is not allowed in global scope');
                }
                return '"' . $this->escapeString($class) . '"';
            case 'Scalar_MagicConst_Method':
                return '"' . $this->escapeString($class) . '::' . $this->escapeString($this->method) . '"';
            default:
                abort($expr);
        }
    }

    protected function detectConstType($expr): string
    {
        $name = $this->parseIdentifier($expr->name);
        if ($this->hasConstant($name)) {
            return $this->getConstantType($name);
        }
        if ($this->isInternalConstant($name)) {
            return $this->getTypeFromZendType(gettype($this->internalConstants[$name]));
        }
        if (strcasecmp($name, 'true') === 0) {
            return self::TYPE_BOOL;
        }
        if (strcasecmp($name, 'false') === 0) {
            return self::TYPE_BOOL;
        }
        if ($name === 'NAN' or $name === 'INF') {
            return self::TYPE_FLOAT;
        }
        return self::TYPE_VAR;
    }

    protected function isInternalScalarConstant(string $name): bool
    {
        return $this->isInternalConstant($name) && is_scalar($this->internalConstants[$name]);
    }

    protected function getInternalScalarConstantValue(string $name): string|int|float
    {
        $value = $this->internalConstants[$name];
        if (is_int($value)) {
            return $this->genIntegerLiteral($value);
        }
        if (is_float($value)) {
            if (is_nan($value)) {
                return self::VALUE_NAN;
            }
            if (is_infinite($value)) {
                return $value > 0 ? self::VALUE_INF : '-' . self::VALUE_INF;
            }
            return $this->genCValue($value);
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value)) {
            return $this->genCharPtr($value, true);
        }
        $this->error('Unsupported constant type: ' . gettype($value));
    }
}
