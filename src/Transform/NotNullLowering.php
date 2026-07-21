<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Transform;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt;
use TypePhp\Exception\SyntaxError;

final class NotNullLowering
{
    public static function validateTarget(Node $node): void
    {
        if (CompileTimeAttribute::has($node, 'NotNull') && !$node instanceof Param) {
            throw new SyntaxError('NotNull can only be applied to function or method parameters');
        }
    }

    public static function lowerFunction(Stmt\Function_|Stmt\ClassMethod|Expr\Closure $function): void
    {
        $checks = [];
        foreach ($function->params as $param) {
            if (!CompileTimeAttribute::consume($param, 'NotNull')) {
                continue;
            }
            if ($function->stmts === null || !is_string($param->var->name)) {
                throw new SyntaxError('NotNull requires a concrete function or method parameter');
            }
            $name = $param->var->name;
            $checks[] = new Stmt\If_(new Expr\Empty_(new Expr\Variable($name)), [
                'stmts' => [new Stmt\Expression(new Expr\Throw_(new Expr\New_(
                    new Node\Name\FullyQualified('ValueError'),
                    [new Node\Arg(new Node\Scalar\String_('Parameter $' . $name . ' must not be empty'))],
                )))],
            ]);
        }
        if ($checks !== []) {
            $function->stmts = [...$checks, ...$function->stmts];
        }
    }

    public static function rejectArrowFunction(Expr\ArrowFunction $function): void
    {
        foreach ($function->params as $param) {
            if (CompileTimeAttribute::has($param, 'NotNull')) {
                throw new SyntaxError('NotNull is not supported on arrow function parameters');
            }
        }
    }
}
