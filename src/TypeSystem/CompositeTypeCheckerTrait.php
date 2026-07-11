<?php
/**
 * This file is part of TypePHP.
 *
 * Evaluates static relations for union, intersection, nullable, and literal types.
 */

namespace TypePhp\TypeSystem;

use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait CompositeTypeCheckerTrait
{
    protected function checkCompositeTypeAssignment(
        NodeAbstract $errorNode,
        array $typeCheck,
        string $typeStr,
        NodeAbstract $value,
        string $context
    ): int {
        $relation = $this->compositeTypeRelation($value, $typeCheck);
        if ($relation !== self::COMPOSITE_TYPE_MISMATCH) {
            return $relation;
        }

        $valueType = $this->staticTypeNameOfExpr($value);
        $this->fatalError($errorNode, "Cannot assign {$valueType} to {$context} of type `{$typeStr}`");
    }

    protected function compositeTypeRelation(NodeAbstract $value, array $clauses): int
    {
        // TYPE_VAR means that the expression is dynamic or its result cannot
        // be represented by the current scalar type system. It must retain the
        // runtime type check.
        if ($this->detectTypeOfExpr($value) === self::TYPE_VAR && !$this->isNullExpr($value)) {
            return self::COMPOSITE_TYPE_UNKNOWN;
        }

        $hasUnknown = false;
        foreach ($clauses as $clause) {
            $relation = $this->compositeTypeClauseRelation($value, $clause);
            if ($relation === self::COMPOSITE_TYPE_MATCH) {
                return self::COMPOSITE_TYPE_MATCH;
            }
            if ($relation === self::COMPOSITE_TYPE_UNKNOWN) {
                $hasUnknown = true;
            }
        }
        return $hasUnknown ? self::COMPOSITE_TYPE_UNKNOWN : self::COMPOSITE_TYPE_MISMATCH;
    }

    protected function compositeTypeClauseRelation(NodeAbstract $value, array $clause): int
    {
        if (($clause['kind'] ?? '') === 'allOf') {
            $hasUnknown = false;
            foreach ($clause['types'] ?? [] as $entry) {
                $relation = $this->compositeTypeEntryRelation($value, $entry);
                if ($relation === self::COMPOSITE_TYPE_MISMATCH) {
                    return self::COMPOSITE_TYPE_MISMATCH;
                }
                if ($relation === self::COMPOSITE_TYPE_UNKNOWN) {
                    $hasUnknown = true;
                }
            }
            return $hasUnknown ? self::COMPOSITE_TYPE_UNKNOWN : self::COMPOSITE_TYPE_MATCH;
        }
        return $this->compositeTypeEntryRelation($value, $clause);
    }

    protected function compositeTypeEntryRelation(NodeAbstract $value, array $entry): int
    {
        $kind = $entry['kind'] ?? '';
        if ($kind === 'isNull') {
            return $this->isNullExpr($value) ? self::COMPOSITE_TYPE_MATCH : self::COMPOSITE_TYPE_MISMATCH;
        }

        $type = $this->detectTypeOfExpr($value);
        return match ($kind) {
            'isInt' => $this->exactCompositeTypeRelation($type, self::TYPE_INT),
            // PHP permits int -> float widening. It is compatible but still
            // needs conversion, so retain the runtime normalization path.
            'isFloat' => $type === self::TYPE_INT
                ? self::COMPOSITE_TYPE_UNKNOWN
                : $this->exactCompositeTypeRelation($type, self::TYPE_FLOAT),
            'isBool' => $this->exactCompositeTypeRelation($type, self::TYPE_BOOL),
            'isString' => $this->exactCompositeTypeRelation($type, self::TYPE_STR),
            'isArray' => $this->exactCompositeTypeRelation($type, self::TYPE_ARRAY),
            'isObject' => $this->exactCompositeTypeRelation($type, self::TYPE_OBJECT),
            'isTrue' => $this->compositeLiteralBoolRelation($value, true),
            'isFalse' => $this->compositeLiteralBoolRelation($value, false),
            'isResource' => $this->exactCompositeTypeRelation($type, self::TYPE_RESOURCE),
            'callable' => $this->compositeCallableRelation($value, $type),
            'iterable' => $this->compositeIterableRelation($value, $type),
            'instanceof' => $this->compositeObjectEntryRelation($value, $entry),
            default => self::COMPOSITE_TYPE_UNKNOWN,
        };
    }

    protected function exactCompositeTypeRelation(string $actual, string $expected): int
    {
        return $actual === $expected ? self::COMPOSITE_TYPE_MATCH : self::COMPOSITE_TYPE_MISMATCH;
    }

    protected function compositeLiteralBoolRelation(NodeAbstract $value, bool $expected): int
    {
        if ($this->isScalarBool($value)) {
            $actual = strcasecmp($value->name->toString(), 'true') === 0;
            return $actual === $expected ? self::COMPOSITE_TYPE_MATCH : self::COMPOSITE_TYPE_MISMATCH;
        }
        return $this->detectTypeOfExpr($value) === self::TYPE_BOOL
            ? self::COMPOSITE_TYPE_UNKNOWN
            : self::COMPOSITE_TYPE_MISMATCH;
    }

    protected function compositeCallableRelation(NodeAbstract $value, string $type): int
    {
        if ($type === self::TYPE_STR || $type === self::TYPE_ARRAY || $type === self::TYPE_OBJECT) {
            return self::COMPOSITE_TYPE_UNKNOWN;
        }
        return self::COMPOSITE_TYPE_MISMATCH;
    }

    protected function compositeIterableRelation(NodeAbstract $value, string $type): int
    {
        if ($type === self::TYPE_ARRAY) {
            return self::COMPOSITE_TYPE_MATCH;
        }
        if ($type !== self::TYPE_OBJECT) {
            return self::COMPOSITE_TYPE_MISMATCH;
        }
        return $this->compositeObjectTypeRelation($value, 'Traversable');
    }

    protected function compositeObjectEntryRelation(NodeAbstract $value, array $entry): int
    {
        if ($this->detectTypeOfExpr($value) !== self::TYPE_OBJECT) {
            return self::COMPOSITE_TYPE_MISMATCH;
        }

        return $this->compositeObjectTypeRelation($value, $entry['class'] ?? '');
    }

    protected function compositeObjectTypeRelation(NodeAbstract $value, string $expected): int
    {
        $class = $this->detectDeclaredClassOfExpr($value);
        if ($class === '') {
            return self::COMPOSITE_TYPE_UNKNOWN;
        }

        if ($expected === '' || $expected === 'static') {
            return self::COMPOSITE_TYPE_UNKNOWN;
        }

        $actualKnown = $this->hasClass($class)
            || $this->hasInterface($class)
            || $this->isInternalClass($class)
            || $this->isInternalInterface($class);
        $expectedKnown = $this->hasClass($expected)
            || $this->hasInterface($expected)
            || $this->isInternalClass($expected)
            || $this->isInternalInterface($expected);
        if (!$actualKnown || !$expectedKnown) {
            return self::COMPOSITE_TYPE_UNKNOWN;
        }

        return $this->isObjectClassStaticallyAssignableTo($class, $expected)
            ? self::COMPOSITE_TYPE_MATCH
            : self::COMPOSITE_TYPE_MISMATCH;
    }

    protected function isNullExpr(NodeAbstract $expr): bool
    {
        return $expr instanceof Expr\ConstFetch
            && strcasecmp($this->parseIdentifier($expr->name), 'null') === 0;
    }

    protected function staticTypeNameOfExpr(NodeAbstract $expr): string
    {
        if ($this->isNullExpr($expr)) {
            return 'null';
        }
        $type = $this->detectTypeOfExpr($expr);
        return match ($type) {
            self::TYPE_INT => 'int',
            self::TYPE_FLOAT => 'float',
            self::TYPE_BOOL => 'bool',
            self::TYPE_STR => 'string',
            self::TYPE_ARRAY => 'array',
            self::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }

}

