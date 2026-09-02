<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Entity;

/**
 * Compiler-internal identity of one enum case, returned when a constant
 * expression is evaluated with identity semantics (see the enumCasesAsIdentity
 * flag of ClassConstantValueTrait): Zend compares the case OBJECTS when
 * flattening traits, so E1::Value and E2::Value are different values even
 * when both share a case name or backing scalar.
 *
 * ConstExprEvaluator can only produce scalars, arrays and null from user
 * code — PHP strings are binary-safe, so even a NUL-prefixed marker string
 * would be spellable by a user constant — which makes an instance of this
 * class impossible to collide with. Instances are interned per (enum class,
 * case name) pair, so `===` is a stable identity test for repeated references
 * to one case, including recursively inside arrays: PHP's `===` on arrays
 * compares each element with `===` again, where interned objects only match
 * themselves.
 */
final class EnumCaseIdentity
{
    /** @var array<string, self> */
    private static array $instances = [];

    private function __construct(
        public readonly string $enumClass,
        public readonly string $caseName,
    ) {
    }

    /**
     * @param string $enumClass fully qualified enum name (class names are
     *                          case-insensitive; a leading `\` is ignored)
     * @param string $caseName case name, compared case-sensitively as Zend does
     */
    public static function intern(string $enumClass, string $caseName): self
    {
        $normalized = strtolower(ltrim($enumClass, '\\'));
        return self::$instances[$normalized . '::' . $caseName]
            ??= new self($normalized, $caseName);
    }
}
