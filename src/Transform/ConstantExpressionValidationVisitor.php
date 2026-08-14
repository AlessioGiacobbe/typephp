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
use PhpParser\NodeVisitorAbstract;
use TypePhp\Exception\SyntaxError;

/**
 * Applies the allow_dynamic values used by php-src at each declaration site.
 *
 * false: class constants, property defaults and enum cases.
 * true: attributes, parameter defaults and global constants.
 *
 * PHP 8.4+ compiles static variable initializers as regular expressions and
 * evaluates them only once, so they do not use the constant-expression path.
 */
final class ConstantExpressionValidationVisitor extends NodeVisitorAbstract
{
    private readonly ConstantExpressionValidator $validator;

    /** @param null|Closure(Node, string): never $fatalError */
    public function __construct(
        string $phpVersion,
        private readonly ?Closure $fatalError = null,
    )
    {
        $this->validator = new ConstantExpressionValidator($phpVersion);
    }

    public function enterNode(Node $node): null
    {
        try {
            return $this->validateNode($node);
        } catch (SyntaxError $error) {
            if ($this->fatalError !== null) {
                ($this->fatalError)($node, $error->getMessage());
            }
            throw $error;
        }
    }

    private function validateNode(Node $node): null
    {
        if ($node instanceof Node\Attribute) {
            $this->validator->validateArguments(
                $node->args,
                allowDynamic: true,
                attributeArgumentList: true,
            );
            return null;
        }

        if ($node instanceof Node\Stmt\ClassConst) {
            foreach ($node->consts as $constant) {
                $this->validator->validate($constant->value, allowDynamic: false);
            }
            return null;
        }

        if ($node instanceof Node\Stmt\Property) {
            foreach ($node->props as $property) {
                if ($property->default !== null) {
                    $this->validator->validate($property->default, allowDynamic: false);
                }
            }
            return null;
        }

        if ($node instanceof Node\Stmt\EnumCase && $node->expr !== null) {
            $this->validator->validate($node->expr, allowDynamic: false);
            return null;
        }

        if ($node instanceof Node\Param && $node->default !== null) {
            $this->validator->validate($node->default, allowDynamic: true);
            return null;
        }

        if ($node instanceof Node\Stmt\Const_) {
            foreach ($node->consts as $constant) {
                $this->validator->validate($constant->value, allowDynamic: true);
            }
            return null;
        }

        return null;
    }
}
