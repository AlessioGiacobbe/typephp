<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Generator;

use PhpAot\Php\CompilerBase;
use PhpAot\Php\Constants;

trait Utils
{
    protected function genCValue(mixed $value): mixed
    {
        if (is_int($value) or is_float($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value)) {
            return $this->genCharPtr($value);
        } else {
            $this->error('Unsupported constant type: ' . gettype($value));
        }
    }

    protected function genCharPtr(string $str, bool $escape = false): string
    {
        return '"' . ($escape ? $this->escapeString($str) : $str) . '"';
    }

    protected function genZendStrl(string $char): string
    {
        return 'ZEND_STRL(' . $this->genCharPtr($char) . ')';
    }

    protected function genArray(array $elements): string
    {
        return CompilerBase::TYPE_ARRAY . '{' . implode(', ', $elements) . ' }';
    }

    protected function genRawStr(string $str): string
    {
        return 'R"(' . $str . ')"';
    }

    protected function escapeString(string $str): string
    {
        $str = addcslashes($str, "\\\"\n\r\t\v\f\0\x01..\x1f\x7f..\xff");
        // C++ trigraph
        return str_replace('??', '\?\?', $str);
    }

    protected function escapeBool(bool $bool): string
    {
        return $bool ? 'true' : 'false';
    }

    protected function escapeVarName(string $name): string
    {
        if (in_array($name, Constants::CPP_RESERVED_NAMES)) {
            return '_php__var__' . $name;
        }
        if ($name === 'this') {
            return 'this_';
        }
        return $name;
    }

    protected function escapeStaticVar(string $name): string
    {
        $prefix = '';
        if ($this->namespace) {
            $prefix .= $this->escapeNamespace($this->namespace) . '_';
        }
        if ($this->class) {
            $prefix .= $this->escapeClass($this->class) . '_';
            if ($this->method) {
                $prefix .= $this->method . '_';
            }
        } else {
            if ($this->function) {
                $prefix .= $this->function . '_';
            }
        }
        return $prefix . $name;
    }

    protected function escapeGlobalVar(string $name): string
    {
        return self::GLOBAL_VAR . $name;
    }

    protected function escapeNamespace(string $ns): string
    {
        return str_replace('\\', self::NAMESPACE_SEPARATOR, strtolower($ns));
    }

    protected function escapeZendFnName(string $fn, bool $lower = true): string
    {
        return str_replace('\\', '_', $lower ? strtolower($fn) : $fn);
    }

    protected function escapeCeName(string $name): string
    {
        return $this->escapeZendFnName($name, false);
    }

    protected function escapeName(string $name): string
    {
        return strtolower($name);
    }

    protected function escapeClass(string $class): string
    {
        return str_replace('\\', '_', trim(strtolower($class), '\\'));
    }

    protected function escapeFunction(string $func): string
    {
        return $this->escapeClass($func);
    }

    protected function escapeFileName(string $file): string
    {
        return str_replace('-', '_', $file);
    }

    protected function unescapeVarName(string $name): string
    {
        return str_replace('_php__var__', '', $name);
    }

    protected function isClosedExpr($expr, $call): bool
    {
        if ($call === '') {
            if (!str_starts_with($expr, '(')) {
                return false;
            }
            $startPos = 0;
        } else {
            if (!str_starts_with($expr, $call . '(')) {
                return false;
            }
            $startPos = strlen($call);
        }

        $bracketCount = 0;
        $length       = strlen($expr);

        for ($i = $startPos; $i < $length; $i++) {
            $char = $expr[$i];
            if ($char === '(') {
                $bracketCount++;
            } elseif ($char === ')') {
                $bracketCount--;
                if ($bracketCount === 0) {
                    return $i === $length - 1;
                }
            }
        }

        return false;
    }

    protected function trimBrackets(string $str): string
    {
        if ($this->isClosedExpr($str, '')) {
            return substr($str, 1, -1);
        }

        return $str;
    }
}
