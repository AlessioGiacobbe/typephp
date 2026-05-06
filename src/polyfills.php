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

    public static function array(mixed $type, int $size): array
    {
        return [];
    }

    public static function fill(array $array, mixed $value): void
    {
        for ($i = 0; $i < count($array); $i++) {
            $array[$i] = $value;
        }
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

function any(mixed $var): mixed
{
    return $var;
}