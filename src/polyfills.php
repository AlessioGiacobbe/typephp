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

class complex_types {
    public const type_any = 'any';
    public const type_var = 'any';
    public const type_variant = 'any';
    public const type_str = 'string';
    public const type_string = 'string';
    public const type_array = 'array';
    public const type_object = 'object';
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

    public static function map(mixed $key_type, mixed $value_type): array
    {
        return [];
    }

    public static function unordered_map(mixed $key_type, mixed $value_type): array
    {
        return [];
    }

    public static function vector(mixed $value_type): array
    {
        return [];
    }

    public static function unsafe_ptr(mixed &$value): mixed
    {
        return null;
    }

    public static function unsafe_cast(mixed $type, mixed $ptr): mixed
    {
        return $ptr;
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

function stream_cast($stream)
{
    return $stream;
}
