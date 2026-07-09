<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Resolver;

use PhpParser\NodeAbstract;

final class PropertyAccessResolver
{
    public function __construct(
        private readonly PropertyAccessContext $compiler,
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
            $class = $this->compiler->getParentClass($class);
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
            $classDef = $this->compiler->getClassDef($findClass);
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

    public function resolveNativeInstanceProperty(
        NodeAbstract $expr,
        string $property,
        string $class,
        string $scope,
    ): ?PropertyAccessResult {
        return $this->resolveNativeProperty($expr, $property, $class, $scope);
    }

    public function resolveNativeStaticProperty(
        NodeAbstract $expr,
        string $property,
        string $class,
        string $scope,
    ): ?PropertyAccessResult {
        return $this->resolveNativeProperty($expr, $property, $class, $scope, true);
    }

    /**
     * @param array<int, array{node: NodeAbstract, property: string}> $properties
     * @return array<int, PropertyAccessResult>
     */
    public function resolveNullsafePropertyChain(
        string $baseClass,
        array $properties,
        string $scope,
        string $objectType,
    ): array {
        if ($baseClass === '') {
            return [];
        }

        $className = $baseClass;
        $resolved = [];
        foreach ($properties as $index => $property) {
            $result = $this->resolveNativeProperty($property['node'], $property['property'], $className, $scope);
            if ($result === null) {
                break;
            }

            $resolved[$index] = $result;
            $def = $result->propertyDef;
            if ($def->type !== $objectType || $def->class === '') {
                break;
            }
            $className = $def->class;
        }

        return $resolved;
    }

    private function fatal(NodeAbstract $expr, string $message): never
    {
        $this->compiler->fatalError($expr, $message);
    }
}
