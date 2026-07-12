<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ExtensionProvider
{
    public const string Keyword = '*';

    public function __construct(public string $target)
    {
    }
}

/**
 * Public compile-time type symbols shared by extension providers and std containers.
 * This root class is deliberately distinct from the compiler-internal TypePhp\Type.
 */
final class Type
{
    public const string Int = 'int';
    public const string Float = 'float';
    public const string Bool = 'bool';
    public const string BigInt = 'bigint';
    public const string BigFloat = 'bigfloat';
    public const string Decimal = 'decimal';
    public const string String = 'string';
    public const string Array = 'array';
    public const string Object = 'object';
    public const string Any = 'any';
    public const string Stream = 'stream';
    public const string Box = 'box';
}

/** @deprecated Compiler directive retained independently of public type symbols. */
class native_types
{
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

    public static function bigInt(mixed $value): mixed
    {
        return $value;
    }

    public static function decimal(mixed $value): mixed
    {
        return $value;
    }

    public static function bigFloat(mixed $value): mixed
    {
        return $value;
    }

    public static function array(mixed $type, int $size): array
    {
        return [];
    }

    public static function ordered_map(mixed $key_type, mixed $value_type): array
    {
        return [];
    }

    public static function map(mixed $key_type, mixed $value_type): array
    {
        return [];
    }

    public static function vector(mixed $value_type, ?int $size = null): array
    {
        return [];
    }
}


function &refval(&$var)
{
    return $var;
}

function any(mixed $var): mixed
{
    return $var;
}

/**
 * @throws Exception
 */
function objval(mixed $var, string $className): mixed
{
    if (!$var instanceof $className) {
        throw new \Exception("Invalid object type: " . get_class($var) . " expected " . $className);
    }
    return $var;
}
