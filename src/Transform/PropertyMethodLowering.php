<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Transform;

use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use TypePhp\Exception\SyntaxError;

final class PropertyMethodLowering
{
    private const ATTRIBUTES = ['Setter', 'With'];

    public static function validateTarget(Node $node): void
    {
        foreach (self::ATTRIBUTES as $attribute) {
            if (!CompileTimeAttribute::has($node, $attribute)) {
                continue;
            }
            if ($node instanceof Stmt\Property && !$node->isStatic()) {
                continue;
            }
            if ($node instanceof Param && $node->isPromoted()) {
                continue;
            }
            throw new SyntaxError($attribute . ' can only be applied to instance properties');
        }
    }

    /** @return list<Stmt\ClassMethod> */
    public static function lowerProperty(Stmt\Property $property): array
    {
        $setter = CompileTimeAttribute::consume($property, 'Setter');
        $with = CompileTimeAttribute::consume($property, 'With');
        if (!$setter && !$with) {
            return [];
        }

        $methods = [];
        foreach ($property->props as $prop) {
            $name = $prop->name->toString();
            if ($setter) {
                $methods[] = self::createSetter($name, $property->type, $property->getAttributes());
            }
            if ($with) {
                $methods[] = self::createWith($name, $property->type, $property->getAttributes());
            }
        }
        return $methods;
    }

    /** @return list<Stmt\ClassMethod> */
    public static function lowerPromotedProperty(Param $param): array
    {
        if (!$param->isPromoted() || !is_string($param->var->name)) {
            return [];
        }
        $setter = CompileTimeAttribute::consume($param, 'Setter');
        $with = CompileTimeAttribute::consume($param, 'With');
        if (!$setter && !$with) {
            return [];
        }

        $methods = [];
        if ($setter) {
            $methods[] = self::createSetter($param->var->name, $param->type, $param->getAttributes());
        }
        if ($with) {
            $methods[] = self::createWith($param->var->name, $param->type, $param->getAttributes());
        }
        return $methods;
    }

    private static function createSetter(string $property, ?Node $type, array $attributes): Stmt\ClassMethod
    {
        return new Stmt\ClassMethod('set' . ucfirst($property), [
            'flags' => Modifiers::PUBLIC,
            'params' => [new Param(new Expr\Variable($property), type: $type === null ? null : clone $type)],
            'returnType' => new Node\Identifier('void'),
            'stmts' => [new Stmt\Expression(new Expr\Assign(
                new Expr\PropertyFetch(new Expr\Variable('this'), $property),
                new Expr\Variable($property),
            ))],
        ], $attributes);
    }

    private static function createWith(string $property, ?Node $type, array $attributes): Stmt\ClassMethod
    {
        return new Stmt\ClassMethod('with' . ucfirst($property), [
            'flags' => Modifiers::PUBLIC,
            'params' => [new Param(new Expr\Variable($property), type: $type === null ? null : clone $type)],
            'returnType' => new Node\Name('static'),
            'stmts' => [
                new Stmt\Expression(new Expr\Assign(
                    new Expr\Variable('clone'),
                    new Expr\Clone_(new Expr\Variable('this')),
                )),
                new Stmt\Expression(new Expr\Assign(
                    new Expr\PropertyFetch(new Expr\Variable('clone'), $property),
                    new Expr\Variable($property),
                )),
                new Stmt\Return_(new Expr\Variable('clone')),
            ],
        ], $attributes);
    }
}
