<?php

namespace PhpAot\Php;

use ReflectionFunction;

class Reflection
{
    private static array $functions = [];

    static function getFunction(string $fn)
    {
        if (!isset(self::$functions[$fn])) {
            self::$functions[$fn] = new ReflectionFunction($fn);
        }
        return self::$functions[$fn];
    }

    static function getFunctionReturnType(string $fn): ?string
    {
        $func = self::getFunction($fn);
        $returnType = $func->getReturnType();
        if (!$returnType) {
            return null;
        }
        if ($returnType instanceof \ReflectionUnionType) {
            return null;
        }
        return $returnType->getName();
    }
}