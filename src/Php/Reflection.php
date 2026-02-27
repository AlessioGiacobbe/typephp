<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

use ReflectionClass;
use ReflectionException;
use ReflectionFunction;
use ReflectionParameter;
use ReflectionUnionType;

class Reflection
{
    private static array $functions = [];
    private static array $classes = [];

    public static function getFunction(string $fn): ?ReflectionFunction
    {
        if (!isset(self::$functions[$fn])) {
            try {
                $ref = new ReflectionFunction($fn);
            } catch (ReflectionException $e) {
                return null;
            }
            self::$functions[$fn] = $ref;
        }

        return self::$functions[$fn];
    }

    public static function getClass(string $className): ?ReflectionClass
    {
        if (!isset(self::$classes[$className])) {
            try {
                $ref = new ReflectionClass($className);
            } catch (ReflectionException $e) {
                return null;
            }
            self::$classes[$className] = $ref;
        }

        return self::$classes[$className];
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

    public static function getClassMethodParameter(string $className, string $fn, int $index): ?ReflectionParameter
    {
        $classRef = self::getClass($className);
        if (!$classRef) {
            return null;
        }

        try {
            $method = $classRef->getMethod($fn);
        } catch (ReflectionException $e) {
            return null;
        }

        $args = $method->getParameters();
        if ($index >= count($args)) {
            return null;
        }
        return $args[$index];
    }

    public static function isReferenceArg(string $fnName, string $className, int $index): ?string
    {
        if ($className) {
            $param = self::getClassMethodParameter($className, $fnName, $index);
        } else {
            $param = self::getFunctionParameter($fnName, $index);
        }
        if (!$param) {
            return null;
        }
        return $param->isPassedByReference() ? $param->getName() : null;
    }
}
