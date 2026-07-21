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
use PhpParser\Node\Stmt;
use TypePhp\Exception\SyntaxError;

final class PrinterLowering
{
    public const GENERATED_ATTRIBUTE = 'typephpPrinterGenerated';

    public static function validateTarget(Node $node): void
    {
        if (!CompileTimeAttribute::has($node, 'Printer')) {
            return;
        }
        if (!$node instanceof Stmt\Class_ || $node->name === null) {
            throw new SyntaxError('Printer can only be applied to named classes');
        }
    }

    public static function lowerClass(Stmt\Class_ $class, bool $generate = true): void
    {
        if (!CompileTimeAttribute::consume($class, 'Printer') || !$generate) {
            return;
        }
        foreach ($class->getMethods() as $method) {
            if ($method->name->toLowerString() === 'tostring') {
                return;
            }
        }

        self::appendGeneratedMethod($class, self::ownPublicProperties($class));
    }

    /** @param list<string> $properties */
    public static function rebuildGeneratedMethod(Stmt\Class_ $class, array $properties): void
    {
        self::removeGeneratedMethod($class);
        self::appendGeneratedMethod($class, array_values(array_unique($properties)));
    }

    public static function removeGeneratedMethod(Stmt\Class_ $class): void
    {
        foreach ($class->stmts as $index => $stmt) {
            if ($stmt instanceof Stmt\ClassMethod && $stmt->getAttribute(self::GENERATED_ATTRIBUTE)) {
                unset($class->stmts[$index]);
            }
        }
        $class->stmts = array_values($class->stmts);
    }

    /** @return list<string> */
    public static function ownPublicProperties(Stmt\Class_ $class): array
    {
        $properties = [];
        foreach ($class->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property && $stmt->isPublic() && !$stmt->isStatic()) {
                foreach ($stmt->props as $property) {
                    $properties[] = $property->name->toString();
                }
            }
            if ($stmt instanceof Stmt\ClassMethod && $stmt->name->toLowerString() === '__construct') {
                foreach ($stmt->params as $param) {
                    if ($param->isPromoted() && ($param->flags & Modifiers::PUBLIC) && is_string($param->var->name)) {
                        $properties[] = $param->var->name;
                    }
                }
            }
        }
        return $properties;
    }

    /** @param list<string> $properties */
    private static function appendGeneratedMethod(Stmt\Class_ $class, array $properties): void
    {
        $expression = new Node\Scalar\String_($class->name->toString() . '(');
        foreach ($properties as $index => $property) {
            $prefix = ($index === 0 ? '' : ', ') . $property . '=';
            $expression = new Expr\BinaryOp\Concat(
                new Expr\BinaryOp\Concat($expression, new Node\Scalar\String_($prefix)),
                new Expr\PropertyFetch(new Expr\Variable('this'), $property),
            );
        }
        $expression = new Expr\BinaryOp\Concat($expression, new Node\Scalar\String_(')'));
        $method = new Stmt\ClassMethod('toString', [
            'flags' => Modifiers::PUBLIC,
            'returnType' => new Node\Identifier('string'),
            'stmts' => [new Stmt\Return_($expression)],
        ]);
        $method->setAttribute(self::GENERATED_ATTRIBUTE, true);
        $class->stmts[] = $method;
    }
}
