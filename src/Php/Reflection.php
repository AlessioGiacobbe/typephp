<?php

namespace PhpAot\Php;

use ReflectionFunction;

class Reflection
{
    private static array $functions = [];

    static function getFunction(string $fn)
    {
        if (!isset(self::$functions[$fn])) {
            try {
                $ref = new ReflectionFunction($fn);;
            } catch (\ReflectionException $e) {
                return null;
            }
            self::$functions[$fn] = $ref;
        }
        return self::$functions[$fn];
    }

    static function getFunctionReturnType(string $fn): ?string
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
}