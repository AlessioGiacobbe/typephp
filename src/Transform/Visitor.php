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
    /** @param null|callable(Stmt\Class_): bool $printerPredicate */
    public function __construct(private $printerPredicate = null)
    {
    }

    public function enterNode(Node $node): null
    {
        GetterLowering::validateTarget($node);
        PropertyMethodLowering::validateTarget($node);
        NotNullLowering::validateTarget($node);
        PrinterLowering::validateTarget($node);
        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod || $node instanceof Node\Expr\Closure) {
            NotNullLowering::lowerFunction($node);
        } elseif ($node instanceof Node\Expr\ArrowFunction) {
            NotNullLowering::rejectArrowFunction($node);
        }

        if (!$node instanceof Stmt\Class_ && !$node instanceof Stmt\Trait_ && !$node instanceof Stmt\Enum_) {
            return null;
        }

        $methods = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property) {
                array_push($methods, ...PropertyHookLowering::lowerProperty($stmt));
                array_push($methods, ...GetterLowering::lowerProperty($stmt));
                array_push($methods, ...PropertyMethodLowering::lowerProperty($stmt));
            } elseif ($stmt instanceof Stmt\ClassMethod && $stmt->name->toLowerString() === '__construct') {
                foreach ($stmt->params as $param) {
                    $marker = PropertyHookLowering::lowerPromotedProperty($param);
                    if ($marker !== null) {
                        $methods[] = $marker;
                    }
                    $getter = GetterLowering::lowerPromotedProperty($param);
                    if ($getter !== null) {
                        $methods[] = $getter;
                    }
                    array_push($methods, ...PropertyMethodLowering::lowerPromotedProperty($param));
                }
            }
        }
        if ($methods !== []) {
            array_push($node->stmts, ...$methods);
        }
        if ($node instanceof Stmt\Class_) {
            if (CompileTimeAttribute::has($node, 'Printer')) {
                $generate = $this->printerPredicate === null || ($this->printerPredicate)($node);
                PrinterLowering::lowerClass($node, $generate);
            }
        }
        return null;
    }
}
