<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Resolver;

use Closure;
use PhpAot\Php\Entity\ClassDef;
use PhpParser\NodeAbstract;

final class PropertyAccessResolver
{
    /**
     * @param Closure(string): ?ClassDef $getClassDef
     * @param Closure(string): string $getParentClass
     */
    public function __construct(
        private readonly Closure $getClassDef,
        private readonly Closure $getParentClass,
        private readonly Closure $fatalError,
    ) {
    }

    public function isSameClassName(string $classA, string $classB): bool
    {
        return strcasecmp(ltrim($classA, '\\'), ltrim($classB, '\\')) === 0;
    }

    public function isSameOrSubclassOf(string $class, string $parent): bool
    {
        $class = strtolower(ltrim($class, '\\'));
        $parent = strtolower(ltrim($parent, '\\'));
        while ($class !== '') {
            if ($class === $parent) {
                return true;
            }
            $class = ($this->getParentClass)($class);
        }
        return false;
    }

    public function canAccessProtectedProperty(string $scope, string $declaringClass): bool
    {
        if ($scope === '') {
            return false;
        }
        return $this->isSameOrSubclassOf($scope, $declaringClass)
            || $this->isSameOrSubclassOf($declaringClass, $scope);
    }

    public function resolveNativeProperty(
        NodeAbstract $expr,
        string $property,
        string $class,
        string $scope,
        bool $static = false,
    ): ?PropertyAccessResult {
        $class = ltrim($class, '\\');
        $findClass = $class;

        while (true) {
            $classDef = ($this->getClassDef)($findClass);
            if ($classDef === null) {
                break;
            }

            if ($classDef->hasProperty($property)) {
                $propertyDef = $classDef->getProperty($property);
                if (!$static && $propertyDef->isStatic()) {
                    $this->fatal($expr, "Cannot access static property `{$class}::\${$property}` as non-static instance property.");
                }
                if ($static && !$propertyDef->isStatic()) {
                    $this->fatal($expr, "Cannot access non-static property `{$class}::\${$property}` as static property.");
                }
                if ($propertyDef->isPublic()) {
                    return new PropertyAccessResult($class, $findClass, $property, $classDef, $propertyDef);
                }
                if ($propertyDef->isProtected()) {
                    if ($this->canAccessProtectedProperty($scope, $findClass)) {
                        return new PropertyAccessResult($class, $findClass, $property, $classDef, $propertyDef);
                    }
                    $displayClass = ltrim($class, '\\');
                    $this->fatal($expr, "Cannot access protected property `{$property}` of class `{$displayClass}`");
                }
                if ($this->isSameClassName($scope, $findClass)) {
                    return new PropertyAccessResult($class, $findClass, $property, $classDef, $propertyDef);
                }
                $displayClass = ltrim($class, '\\');
                $this->fatal($expr, "Cannot access private property `{$property}` of class `{$displayClass}`");
            }

            if (!$classDef->extends) {
                break;
            }
            $findClass = $classDef->extends;
        }

        return null;
    }

    private function fatal(NodeAbstract $expr, string $message): never
    {
        ($this->fatalError)($expr, $message);
    }
}
