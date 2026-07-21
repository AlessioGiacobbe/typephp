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

final class GetterLowering
{
    public static function validateTarget(Node $node): void
    {
        if (!CompileTimeAttribute::has($node, 'Getter')) {
            return;
        }

        if ($node instanceof Stmt\Property) {
            if ($node->isStatic()) {
                throw new SyntaxError('Getter can only be applied to instance properties');
            }
            return;
        }

        if ($node instanceof Param && $node->isPromoted()) {
            return;
        }

        throw new SyntaxError('Getter can only be applied to instance properties');
    }

    /** @return list<Stmt\ClassMethod> */
    public static function lowerProperty(Stmt\Property $property): array
    {
        if (!CompileTimeAttribute::consume($property, 'Getter')) {
            return [];
        }

        $methods = [];
        foreach ($property->props as $prop) {
            $methods[] = self::createGetter(
                $prop->name->toString(),
                $property->type,
                $property->getAttributes(),
            );
        }
        return $methods;
    }

    public static function lowerPromotedProperty(Param $param): ?Stmt\ClassMethod
    {
        if (!$param->isPromoted() || !is_string($param->var->name) || !CompileTimeAttribute::consume($param, 'Getter')) {
            return null;
        }

        return self::createGetter($param->var->name, $param->type, $param->getAttributes());
    }

    private static function createGetter(string $property, ?Node $type, array $attributes): Stmt\ClassMethod
    {
        return new Stmt\ClassMethod('get' . ucfirst($property), [
            'flags' => Modifiers::PUBLIC,
            'returnType' => $type === null ? null : clone $type,
            'stmts' => [new Stmt\Return_(new Expr\PropertyFetch(
                new Expr\Variable('this'),
                $property,
            ))],
        ], $attributes);
    }

}
