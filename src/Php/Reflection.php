<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

class Reflection
{
    private static array $functions = [];
    private static array $classes = [];
    private static array $interfaces = [];

    public static function isInternalClass(string $class): bool
    {
        static $internalClasses = null;

        if ($internalClasses === null) {
            $allClasses = get_declared_classes();

            $internalClasses = [];
            foreach ($allClasses as $className) {
                try {
                    $ref = new \ReflectionClass($className);
                    if ($ref->isInternal()) {
                        $internalClasses[strtolower($className)] = true;
                    }
                } catch (\ReflectionException) {
                    continue;
                }
            }
        }

        return isset($internalClasses[strtolower($class)]);
    }

    public static function isInternalInterface(string $interface): bool
    {
        static $internalInterfaces = null;

        if ($internalInterfaces === null) {
            $allInterfaces = get_declared_interfaces();
            $internalInterfaces = [];
            foreach ($allInterfaces as $interfaceName) {
                try {
                    $ref = new \ReflectionClass($interfaceName);
                    if ($ref->isInternal()) {
                        $internalInterfaces[strtolower($interfaceName)] = true;
                    }
                } catch (\ReflectionException) {
                    continue;
                }
            }
        }

        return isset($internalInterfaces[strtolower($interface)]);
    }

    public static function getFunction(string $fn): ?\ReflectionFunction
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

    public static function getClass(string $className): ?\ReflectionClass
    {
        if (!isset(self::$classes[$className])) {
            try {
                $ref = new \ReflectionClass($className);
            } catch (\ReflectionException $e) {
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

    public static function getClassMethodModifiers(string $className, string $fn): ?int
    {
        $classRef = self::getClass($className);
        if (!$classRef) {
            return null;
        }

        try {
            $method = $classRef->getMethod($fn);
            return $method->getModifiers();
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    public static function getClassMethodParameter(string $className, string $fn, int $index): ?\ReflectionParameter
    {
        $classRef = self::getClass($className);
        if (!$classRef) {
            return null;
        }

        try {
            $method = $classRef->getMethod($fn);
        } catch (\ReflectionException $e) {
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

    public static function hasMethod(string $extends, string $method): bool
    {
        $class = self::getClass($extends);
        if (!$class) {
            return false;
        }
        return $class->hasMethod($method);
    }

    public static function getMethodReturnType(string $class, string $method): ?string
    {
        $classRef = self::getClass($class);
        if (!$classRef) {
            return null;
        }
        if (!$classRef->hasMethod($method)) {
            return null;
        }
        $methodDef = $classRef->getMethod($method);
        return $methodDef->getReturnType() ? $methodDef->getReturnType()->getName() : null;
    }
}
