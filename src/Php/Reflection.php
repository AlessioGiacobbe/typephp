<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

declare(strict_types=1);

namespace PhpAot\Php;

class Reflection
{
    private static array $functions = [];

    public static function getFunction(string $fn)
    {
        if (!isset(self::$functions[$fn])) {
            try {
                $ref = new \ReflectionFunction($fn);
            } catch (\ReflectionException $e) {
                return null;
            }
            self::$functions[$fn] = $ref;
        }

        return self::$functions[$fn];
    }

    public static function getFunctionReturnType(string $fn): ?string
    {
        $func = self::getFunction($fn);
        if (!$func) {
            return null;
        }
        $returnType = $func->getReturnType();
        if (!$returnType) {
            return null;
        }
        if ($returnType instanceof \ReflectionUnionType) {
            return null;
        }

        return $returnType->getName();
    }

    public static function getFunctionParameter(string $fn, int $index): ?\ReflectionParameter
    {
        $func = self::getFunction($fn);
        if (!$func) {
            return null;
        }
        $args = $func->getParameters();
        if ($index >= count($args)) {
            return null;
        }

        return $args[$index];
    }

    public static function isReferenceArg(string $fn, int $index): ?string
    {
        $param = self::getFunctionParameter($fn, $index);
        if (!$param) {
            return null;
        }

        return $param->isPassedByReference() ? $param->getName() : null;
    }
}
