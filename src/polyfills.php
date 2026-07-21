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
    public function __construct(public string $target)
    {
    }
}

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
final readonly class NoExport
{
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Getter
{
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Setter
{
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class With
{
}

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Printer
{
}

#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class NotNull
{
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

function expected(mixed $condition): bool
{
    return (bool) $condition;
}

function unexpected(mixed $condition): bool
{
    return (bool) $condition;
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
