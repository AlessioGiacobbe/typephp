<?php

namespace PhpAot\Php;

use ReflectionFunction;
use ReflectionParameter;
use ReflectionUnionType;

class Reflection
{
    private static array $functions = [];

    public static function getFunction(string $fn)
    {
        if (!isset(self::$functions[$fn])) {
            try {
                $ref = new ReflectionFunction($fn);
                ;
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
        if ($returnType instanceof ReflectionUnionType) {
            return null;
        }
        return $returnType->getName();
    }

    public static function getFunctionParameter(string $fn, int $index): ?ReflectionParameter
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
}
