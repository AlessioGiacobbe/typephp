<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Transform;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Stmt;
use PhpParser\NodeVisitorAbstract;
use TypePhp\Exception\CompileTimeAttributeError;
use TypePhp\Exception\SyntaxError;
use TypePhp\Diagnostics\CompileTimeAttributeDiagnostic;

class Visitor extends NodeVisitorAbstract
{
    /** @param null|Closure(Node, string): void $warning */
    public function __construct(
        private readonly ?Closure $warning = null,
        private readonly string $sourceFile = '',
    ) {
    }

    public function enterNode(Node $node): null
    {
        $this->guard($node, static fn () => CompileTimeAttribute::validateNode($node));
        $this->guard($node, static fn () => NativeClassAttributeLowering::lower($node), 'Native');
        $this->guard($node, static fn () => FunctionAttributeLowering::lower($node));
        $this->guard($node, static fn () => GetterLowering::validateTarget($node), 'Getter');
        $this->guard($node, static fn () => PropertyMethodLowering::validateTarget($node));
        $this->guard($node, static fn () => ConstructorLowering::validateTarget($node), 'Constructor');
        return null;
    }

    public function leaveNode(Node $node): null
    {
        if ($node instanceof Stmt\Function_ || $node instanceof Stmt\ClassMethod || $node instanceof Node\Expr\Closure) {
            $this->guard(
                $node,
                fn () => ParameterValidationLowering::lowerFunction($node, $this->warning),
            );
        } elseif ($node instanceof Node\Expr\ArrowFunction) {
            $this->guard($node, static fn () => ParameterValidationLowering::rejectArrowFunction($node));
        }

        if ($node instanceof Stmt\Interface_) {
            foreach ($node->stmts as $stmt) {
                if ($stmt instanceof Stmt\Property) {
                    PropertyHookLowering::markAbstractInterfaceProperty($stmt);
                }
            }
            return null;
        }

        if (!$node instanceof Stmt\Class_ && !$node instanceof Stmt\Trait_ && !$node instanceof Stmt\Enum_) {
            return null;
        }

        $methods = [];
        $classReadonly = $node instanceof Stmt\Class_ && $node->isReadonly();
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Stmt\Property) {
                foreach (PropertyHookLowering::lowerProperty($stmt) as $method) {
                    $methods[] = $method;
                }
                foreach ($this->guard(
                    $stmt,
                    static fn () => GetterLowering::lowerProperty($stmt),
                    'Getter',
                ) as $method) {
                    $methods[] = $method;
                }
                foreach ($this->guard(
                    $stmt,
                    static fn () => PropertyMethodLowering::lowerProperty($stmt, $classReadonly),
                ) as $method) {
                    $methods[] = $method;
                }
            } elseif ($stmt instanceof Stmt\ClassMethod && $stmt->name->toLowerString() === '__construct') {
                foreach ($stmt->params as $param) {
                    $marker = PropertyHookLowering::lowerPromotedProperty($param);
                    if ($marker !== null) {
                        $methods[] = $marker;
                    }
                    $getter = $this->guard(
                        $param,
                        static fn () => GetterLowering::lowerPromotedProperty($param),
                        'Getter',
                    );
                    if ($getter !== null) {
                        $methods[] = $getter;
                    }
                    foreach ($this->guard(
                        $param,
                        static fn () => PropertyMethodLowering::lowerPromotedProperty($param, $classReadonly),
                    ) as $method) {
                        $methods[] = $method;
                    }
                }
            }
        }
        if ($methods !== []) {
            foreach ($methods as $method) {
                $node->stmts[] = $method;
            }
        }
        $this->guard($node, static fn () => ConstructorLowering::lowerClassLike($node), 'Constructor');
        if ($node instanceof Stmt\Class_) {
            if (CompileTimeAttribute::find($node, 'Printer') !== null) {
                $this->guard($node, static fn () => PrinterLowering::lowerClass($node), 'Printer');
            }
            if (CompileTimeAttribute::find($node, 'Arrayable') !== null) {
                $this->guard($node, static fn () => ArrayableLowering::lowerClass($node), 'Arrayable');
            }
        }
        return null;
    }

    private function guard(Node $target, Closure $operation, ?string $attribute = null): mixed
    {
        try {
            return $operation();
        } catch (SyntaxError $error) {
            if (str_contains($error->getMessage(), '[compile-time attribute:')) {
                throw $error;
            }
            $source = $target;
            $conflictAttribute = null;
            $conflictSource = null;
            if ($error instanceof CompileTimeAttributeError) {
                $target = $error->target;
                $attribute = $error->attribute ?? $attribute;
                $source = $error->attributeSource ?? $target;
                $conflictAttribute = $error->conflictAttribute;
                $conflictSource = $error->conflictSource;
            } else {
                [$detected, $attributeSource] = $this->detectAttribute($target);
                $attribute ??= $detected;
                $source = $attributeSource ?? $target;
            }

            $attribute ??= 'unknown';
            $file = $this->sourceFile !== '' ? $this->sourceFile : '<unknown>';
            throw new SyntaxError(CompileTimeAttributeDiagnostic::format(
                $error->getMessage(),
                $attribute,
                $target,
                $file,
                $source,
                $conflictAttribute,
                $conflictSource,
            ), 0, $error);
        }
    }

    /** @return array{?string, ?Node} */
    private function detectAttribute(Node $node): array
    {
        if (!property_exists($node, 'attrGroups')) {
            return [null, null];
        }
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $definition = CompileTimeAttributeRegistry::get(CompileTimeAttribute::resolvedName($attribute));
                if ($definition !== null) {
                    return [$definition['name'], $attribute];
                }
            }
        }
        return [null, null];
    }

}
