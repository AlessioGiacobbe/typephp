<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Resolver;

use PhpAot\Php\CompilerBase;
use PhpAot\Php\Entity\PropertyDef;

final class PropertyAssignTypeInfo
{
    public function getFixedDefaultValue(PropertyDef $def): ?string
    {
        return match ($def->type) {
            CompilerBase::TYPE_INT => $def->default ?? '0',
            CompilerBase::TYPE_FLOAT => $def->default ?? '0.0',
            CompilerBase::TYPE_BOOL => $def->default ?? 'false',
            CompilerBase::TYPE_STR => $def->default ?? CompilerBase::TYPE_STR . '()',
            CompilerBase::TYPE_ARRAY => $def->default ?? CompilerBase::TYPE_ARRAY . '{}',
            default => null,
        };
    }

    public function isFixed(PropertyDef $def): bool
    {
        return in_array($def->type, [
            CompilerBase::TYPE_INT,
            CompilerBase::TYPE_FLOAT,
            CompilerBase::TYPE_BOOL,
            CompilerBase::TYPE_STR,
            CompilerBase::TYPE_ARRAY,
        ], true) && !$def->nullable;
    }

    public function getRuntimeTypeCheck(PropertyDef $def): array
    {
        if (!empty($def->typeCheck)) {
            return $def->typeCheck;
        }
        $check = [];
        if ($def->nullable) {
            $check[] = ['kind' => 'isNull'];
        }
        $scalarCheck = match ($def->type) {
            CompilerBase::TYPE_INT => [['kind' => 'isInt']],
            CompilerBase::TYPE_FLOAT => [['kind' => 'isFloat'], ['kind' => 'isInt']],
            CompilerBase::TYPE_BOOL => [['kind' => 'isBool']],
            CompilerBase::TYPE_STR => [['kind' => 'isString']],
            CompilerBase::TYPE_ARRAY => [['kind' => 'isArray']],
            default => null,
        };
        if ($scalarCheck !== null) {
            return array_merge($check, $scalarCheck);
        }
        if ($def->type !== CompilerBase::TYPE_OBJECT || $def->class === '') {
            return [];
        }

        $check[] = ['kind' => 'instanceof', 'class' => $def->class];
        return $check;
    }

    public function getTypeString(PropertyDef $def): string
    {
        if ($def->typeStr !== '') {
            return $def->typeStr;
        }
        if ($def->class !== '') {
            return ($def->nullable ? '?' : '') . $def->class;
        }
        return match ($def->type) {
            CompilerBase::TYPE_INT => 'int',
            CompilerBase::TYPE_FLOAT => 'float',
            CompilerBase::TYPE_BOOL => 'bool',
            CompilerBase::TYPE_STR => 'string',
            CompilerBase::TYPE_ARRAY => 'array',
            CompilerBase::TYPE_OBJECT => 'object',
            default => $def->type,
        };
    }
}
