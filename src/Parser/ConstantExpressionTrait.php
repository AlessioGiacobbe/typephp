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
        if (strcasecmp($name, 'null') === 0) {
            return self::VALUE_NULL;
        }
        if (strcasecmp($name, 'true') === 0) {
            return 'true';
        }
        if (strcasecmp($name, 'false') === 0) {
            return 'false';
        }
        if ($this->isNameExpr($expr->name)) {
            if (str_contains($name, '::')) {
                $ns = explode('::', $name)[0];
                $fullName = $this->getNamespacedClassName($ns[0]);
                $ce = $this->getClassEntryPtr($fullName);
                return Symbol::constant() . '(' . $ce . ', ' . $this->getLiteralString($ns[1]) . ')';
            }

            if (isset($this->useConstants[$name])) {
                $name = $this->useConstants[$name];
            } elseif ($expr->name->isUnqualified()) {
                if ($this->namespace) {
                    $namespacedName = $this->namespace . '\\' . $name;
                    if ($this->hasConstant($namespacedName)) {
                        return $this->getConstant($namespacedName);
                    }

                    // PHP resolves an unqualified constant in a namespace at
                    // runtime: first Namespace\NAME, then the global NAME. AOT
                    // cannot select only the namespaced spelling because a
                    // define() call may execute before this fetch.
                    return Symbol::constant() . '('
                        . $this->getLiteralString($namespacedName)
                        . ', php::ConstantLookup::UnqualifiedInNamespace)';
                }
                // A class import with the same alias does not affect a bare
                // constant fetch. Only `use const` participates here.
            } elseif ($expr->name instanceof Node\Name\FullyQualified) {
                // parseIdentifier() has already removed the leading slash.
            } else {
                $fullName = $this->getNamespacedClassName($name);
                if ($fullName) {
                    $name = $fullName;
                }
            }

            if ($this->hasConstant($name)) {
                return $this->getConstant($name);
            }
            if ($name === 'PHP_EOL') {
                return '"' . $this->escapeString(PHP_EOL) . '"';
            }
            if ($this->isInternalScalarConstant($name)) {
                return $this->getInternalScalarConstantValue($name);
            }
            if ($this->isInternalConstant($name)) {
                return Symbol::constant() . '(' . $this->getLiteralString($name) . ')';
            }

            return Symbol::constant() . '(' . $this->getLiteralString($name) . ')';
        }
        return Symbol::constant() . '("' . $this->escapeString($name) . '")';
    }

    protected function parseMagicConst(MagicConst $expr): string
    {
        $class = $this->classDef?->getNamespacedName(false)
            ?? (($this->namespace ? $this->namespace . '\\' : '') . $this->class);
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
                if ($this->methodDef?->traitOrigin !== '') {
                    return '"' . $this->escapeString($this->methodDef->traitOrigin) . '"';
                }
                if (!$this->classDef or !$this->classDef->trait) {
                    $this->fatalError($expr, 'The magic constant `__TRAIT__` is not allowed in global scope');
                }
                return '"' . $this->escapeString($class) . '"';
            case 'Scalar_MagicConst_Method':
                return '"' . $this->escapeString($class) . '::' . $this->escapeString($this->method) . '"';
            default:
                abort($expr);
                break;
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
            return Type::BOOL;
        }
        if (strcasecmp($name, 'false') === 0) {
            return Type::BOOL;
        }
        if ($name === 'NAN' or $name === 'INF') {
            return Type::FLOAT;
        }
        return Type::VAR;
    }

    protected function isInternalScalarConstant(string $name): bool
    {
        return $this->isInternalConstant($name) && is_scalar($this->internalConstants[$name]);
    }

    protected function getInternalScalarConstantValue(string $name): string
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
