<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Transform;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * Applies the allow_dynamic values used by php-src at each declaration site.
 *
 * false: class constants, property defaults and enum cases.
 * true: attributes, parameter defaults, global constants and static variables.
 */
final class ConstantExpressionValidationVisitor extends NodeVisitorAbstract
{
    private readonly ConstantExpressionValidator $validator;

    public function __construct(string $phpVersion)
    {
        $this->validator = new ConstantExpressionValidator($phpVersion);
    }

    public function enterNode(Node $node): null
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

        if ($node instanceof Node\Stmt\Static_) {
            foreach ($node->vars as $variable) {
                if ($variable->default !== null) {
                    $this->validator->validate($variable->default, allowDynamic: true);
                }
            }
        }

        return null;
    }
}
