<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Transform;

use PhpParser\Node;
use TypePhp\Exception\SyntaxError;

final class CompileTimeAttribute
{
    public static function has(Node $node, string $name): bool
    {
        if (!property_exists($node, 'attrGroups')) {
            return false;
        }
        foreach ($node->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (self::is($attribute, $name)) {
                    self::validateArguments($attribute, $name);
                    return true;
                }
            }
        }
        return false;
    }

    public static function consume(Node $node, string $name): bool
    {
        $found = false;
        foreach ($node->attrGroups as $groupIndex => $group) {
            foreach ($group->attrs as $attributeIndex => $attribute) {
                if (!self::is($attribute, $name)) {
                    continue;
                }
                self::validateArguments($attribute, $name);
                $found = true;
                unset($group->attrs[$attributeIndex]);
            }
            $group->attrs = array_values($group->attrs);
            if ($group->attrs === []) {
                unset($node->attrGroups[$groupIndex]);
            }
        }
        $node->attrGroups = array_values($node->attrGroups);
        return $found;
    }

    public static function is(Node\Attribute $attribute, string $name): bool
    {
        $resolvedName = $attribute->name->getAttribute('resolvedName')
            ?? $attribute->name->getAttribute('namespacedName')
            ?? $attribute->name;

        return strcasecmp(ltrim($resolvedName->toString(), '\\'), $name) === 0;
    }

    private static function validateArguments(Node\Attribute $attribute, string $name): void
    {
        if ($attribute->args !== []) {
            throw new SyntaxError($name . ' does not accept arguments');
        }
    }
}
