<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Transform;

use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;

class Visitor extends NodeVisitorAbstract
{
    public function leaveNode(Node $node): null
    {
        if (!$node instanceof Stmt\Class_ && !$node instanceof Stmt\Trait_ && !$node instanceof Stmt\Enum_) {
            return null;
        }

        $methods = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property) {
                array_push($methods, ...PropertyHookLowering::lowerProperty($stmt));
            } elseif ($stmt instanceof Stmt\ClassMethod && $stmt->name->toLowerString() === '__construct') {
                foreach ($stmt->params as $param) {
                    $marker = PropertyHookLowering::lowerPromotedProperty($param);
                    if ($marker !== null) {
                        $methods[] = $marker;
                    }
                }
            }
        }
        if ($methods !== []) {
            array_push($node->stmts, ...$methods);
        }
        return null;
    }
}
