<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

class native_types
{
    public const type_int = 'int';
    public const type_float = 'float';
    public const type_bool = 'bool';
}

class std
{
    public static function int(mixed $value): int
    {
        return intval($value);
    }

    public static function float(mixed $value): float
    {
        return floatval($value);
    }

    public static function bool(mixed $value): bool
    {
        return boolval($value);
    }
}

function objval(mixed $obj, string $class): object
{
    if ($obj instanceof $class) {
        return $obj;
    }
    throw new Exception('Invalid object type');
}

function &refval(&$var)
{
    return $var;
}
