<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Resolver;

use TypePhp\Type;

use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PhpParser\NodeAbstract;

trait NameResolutionTrait
{
    public function getNamespacedClassName(string $class, string $currentNamespace = ''): string
    {
        if ($class === '') {
            $this->error('Class name can not be empty');
        }
        if ($class[0] === '\\') {
            return ltrim($class, '\\');
        }

        $ns2 = explode('\\', trim($class, '\\'));

        $aliasTarget = $this->getClassImportAlias($ns2[0]);
        if ($aliasTarget !== null) {
            $ns = '\\' . $aliasTarget;
            _return:
            if (count($ns2) > 1) {
                $ns .= '\\' . implode('\\', array_slice($ns2, 1));
            }
            return ltrim($ns, '\\');
        }

        foreach ($this->useNamespaces as $useNamespace) {
            $ns1 = explode('\\', trim($useNamespace, '\\'));
            if (strcasecmp($ns1[array_key_last($ns1)], $ns2[0]) === 0) {
                $ns = '\\' . implode('\\', $ns1);
                goto _return;
            }
        }

        // Handle qualified names that exactly match a use import (e.g. the extends
        // of an anonymous class may already be a qualified name like "A\B\C" when the
        // use import is also "A\B\C").
        if (count($ns2) > 1) {
            foreach ($this->useNamespaces as $useNamespace) {
                if (strcasecmp(trim($useNamespace, '\\'), $class) === 0) {
                    return $class;
                }
            }
        }

        if (!$currentNamespace) {
            $currentNamespace = $this->namespace;
        }
        if (!empty($currentNamespace)) {
            return trim($currentNamespace, '\\') . '\\' . $class;
        }

        return $class;
    }

    /**
     * Upgrade the class-name Name node in a trait method parameter to Name\FullyQualified.
     * For qualified names (containing \) already resolved by parseTypeDecl(), upgrade the node type directly;
     * for unresolved unqualified names (such as the inner type of a NullableType, which parseTypeDecl skips by returning TYPE_VAR),
     * resolve them via useAliases/useNamespaces first and then upgrade.
     * gen_stub.php's SimpleType::fromNode() relies on isFullyQualified() to decide whether to re-resolve;
     * if the name is not upgraded to FullyQualified, the current namespace prefix is wrongly appended once the context is lost.
     */
    protected function upgradeToFullyQualifiedName(?NodeAbstract $type): ?NodeAbstract
    {
        if ($type === null) {
            return null;
        }
        if ($type instanceof Node\NullableType) {
            return new Node\NullableType($this->upgradeToFullyQualifiedName($type->type));
        }
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $i => $subType) {
                $type->types[$i] = $this->upgradeToFullyQualifiedName($subType);
            }
            return $type;
        }
        if ($type instanceof Node\IntersectionType) {
            foreach ($type->types as $i => $subType) {
                $type->types[$i] = $this->upgradeToFullyQualifiedName($subType);
            }
            return $type;
        }
        if ($type instanceof Node\Name\FullyQualified) {
            return $type;
        }
        if ($type instanceof Node\Name) {
            $typeName = $type->toString();
            if (isset($this->zendTypeMap[strtolower($typeName)]) || in_array(strtolower($typeName), ['self', 'static', 'parent'], true)) {
                return $type;
            }
            // NameResolver has already applied the declaring file's namespace
            // imports. Keep that canonical identity when a trait signature is
            // copied into its consuming class. In particular, after
            // `use X\X; use X\Y;`, resolving Y produces X\Y; feeding that string
            // through the import table again would incorrectly produce X\X\Y.
            $resolvedName = $type->getAttribute('resolvedName');
            if ($resolvedName instanceof Node\Name\FullyQualified) {
                return new Node\Name\FullyQualified($resolvedName->toString(), $type->getAttributes());
            }
            $resolved = $typeName;
            $firstSegment = explode('\\', $typeName, 2)[0];
            $hasImportedPrefix = $this->getClassImportAlias($firstSegment) !== null;
            if (!$hasImportedPrefix) {
                foreach ($this->useNamespaces as $useNamespace) {
                    $segments = explode('\\', trim($useNamespace, '\\'));
                    if (strcasecmp($segments[array_key_last($segments)], $firstSegment) === 0) {
                        $hasImportedPrefix = true;
                        break;
                    }
                }
            }
            if (!$type->isQualified() || $hasImportedPrefix) {
                $resolved = $this->getNamespacedClassName($typeName);
            }
            return new Node\Name\FullyQualified($resolved, $type->getAttributes());
        }
        return $type;
    }

    private function getClassImportAlias(string $name): ?string
    {
        return $this->useAliases[strtolower($name)] ?? null;
    }

    /**
     * Process the function name and prepend the namespace when required.
     */
    public function getNamespacedFuncName(string $funcName): string
    {
        if ($funcName[0] == '\\') {
            return ltrim($funcName, '\\');
        }
        if (isset($this->useFunctions[$funcName])) {
            return $this->useFunctions[$funcName];
        }
        return $funcName;
    }

    /**
     * @param string $class must be a fully qualified class name including the namespace
     */
    protected function resolveTypeDecl(?NodeAbstract $type, int $what): array
    {
        $class = '';
        $declaredType = $this->parseTypeDecl($type, $what, $class);
        return [$declaredType, $class];
    }

    protected function parseTypeDecl(?NodeAbstract $type, int $what, string &$class): string
    {
        // An undefined type is treated as var (mixed, any)
        if ($type === null) {
            return Type::VAR;
        }
        $this->assertTypeDeclIntersectionsHaveNoCallable($type);
        $this->validateCompoundTypeDecl($type);
        if ($type instanceof UnionType || $type instanceof NullableType || $type instanceof IntersectionType) {
            // Complex types are uniformly treated as mixed/var at the static stage; the runtime typeCheck provides the fallback.
            return Type::VAR;
        } else {
            $typeName = $this->parseIdentifier($type);
            $typeNameLower = strtolower($typeName);
            // Property and class-constant types cannot be declared void/never; only return types can
            if ($what !== self::DECL_TYPE_OF_RETURN and ($typeNameLower === 'void' or $typeNameLower === 'never')) {
                $this->fatalError($type, 'The type `void`/`never` is allowed only for return type');
            } elseif (isset($this->zendTypeMap[$typeNameLower])) {
                return $this->getTypeFromZendType($typeNameLower);
            } else {
                if ($typeName === 'self') {
                    $class = $this->getFullClassLikeName();
                } elseif ($typeName === 'parent') {
                    if (!$this->classDef) {
                        $this->fatalError($type, 'Cannot use "parent" type declaration outside a class');
                    }
                    $class = $this->classDef->extends;
                } elseif ($typeName === 'static') {
                    // The static class cannot be determined at compile time
                    $class = '';
                } else {
                    $class = $this->getNamespacedClassName($typeName);
                }
                // When a trait is injected into a class, the fully qualified class name is required
                if ($class and $this->classDef and $this->classDef->trait) {
                    $type->name = $class;
                }
                return Type::OBJECT;
            }
        }
    }

    /**
     * Zend rejects `callable` as an intersection member while compiling the
     * type itself ("Type callable cannot be part of an intersection type"),
     * in every declaration context - parameters, returns, properties,
     * promoted properties, class and interface constants, closures - and
     * before any property/constant-specific rule fires (probed on 8.4.13:
     * `callable|(Traversable&callable)` reports the intersection conflict,
     * not the property one). Running the walk here, on the common
     * declaration path, covers bare intersections and DNF members like
     * `(Traversable&callable)|stdClass`; without it the type reaches
     * gen_stub, which asserts that intersection members are never builtin.
     */
    private function assertTypeDeclIntersectionsHaveNoCallable(NodeAbstract $typeNode): void
    {
        if ($typeNode instanceof NullableType) {
            $this->assertTypeDeclIntersectionsHaveNoCallable($typeNode->type);
            return;
        }
        if ($typeNode instanceof UnionType) {
            foreach ($typeNode->types as $member) {
                $this->assertTypeDeclIntersectionsHaveNoCallable($member);
            }
            return;
        }
        if ($typeNode instanceof IntersectionType) {
            foreach ($typeNode->types as $member) {
                if (strtolower($this->parseIdentifier($member)) === 'callable') {
                    $this->fatalError($member, 'Type callable cannot be part of an intersection type');
                }
            }
        }
    }

    /**
     * Compile-time well-formedness of compound type declarations, mirroring
     * Zend: standalone-only types inside unions, invalid nullable targets,
     * duplicate members (after alias/namespace resolution, with iterable
     * expanded to array|Traversable), the bool/true/false overlaps,
     * non-class standard types and class-scope keywords (self, parent,
     * static) inside intersections, whether bare or DNF, redundancy between
     * whole DNF groups (a repeated member set in any order, or a group
     * strictly more restrictive than another group or plain class member),
     * and `object` absorbing every class type.
     *
     * Lives on the common declaration path in parseTypeDecl() so both
     * compilation phases share the same rules: preprocessing covers named
     * functions, methods, properties, and constants, while closure and
     * arrow-function signatures inside function bodies are only resolved
     * during conversion. The class-scope keyword rules ("no class scope",
     * "no parent") deliberately stay out of this path, applied per context
     * by the preprocessor: Zend compiles a global closure declaring
     * self/static because it may later be bound to a class scope, yet even
     * there still rejects those keywords inside an intersection (probed on
     * 8.4.13), which is why the intersection rule below is unconditional.
     */
    private function validateCompoundTypeDecl(?NodeAbstract $type): void
    {
        if ($type instanceof NullableType) {
            $inner = $type->type;
            if (!$inner instanceof Node\Identifier && !$inner instanceof Node\Name) {
                return;
            }
            $innerLower = strtolower($this->parseIdentifier($inner));
            if ($innerLower === 'mixed') {
                $this->fatalError($type, 'Type `mixed` cannot be marked as nullable since mixed already includes null');
            }
            if ($innerLower === 'null') {
                $this->fatalError($type, '`null` cannot be marked as nullable');
            }
            if ($innerLower === 'void' || $innerLower === 'never') {
                $this->fatalError($type, "Type `{$innerLower}` can only be used as a standalone type");
            }
            return;
        }
        if ($type instanceof UnionType) {
            $this->validateUnionTypeDecl($type);
        } elseif ($type instanceof IntersectionType) {
            $this->validateIntersectionTypeDecl($type);
        }
    }

    private function validateUnionTypeDecl(UnionType $type): void
    {
        $seen = [];
        $addMember = function (string $key, string $display, NodeAbstract $node) use (&$seen): void {
            if (isset($seen[$key])) {
                $this->fatalError($node, "Duplicate type `{$display}` is redundant");
            }
            $seen[$key] = true;
        };
        // Rendered like Zend's zend_type_to_string(): class types keep their
        // source order in front, standard types follow in a fixed order.
        $classish = [];
        $builtins = [];
        $hasObject = false;
        $hasClassType = false;
        // Every DNF group and plain class member, as a canonical member set,
        // for Zend's whole-list redundancy comparison.
        $groups = [];
        foreach ($type->types as $member) {
            if ($member instanceof IntersectionType) {
                // A DNF group: its members obey the intersection rules.
                $groupMembers = $this->validateIntersectionTypeDecl($member);
                $display = implode('&', $groupMembers);
                $classish[] = '(' . $display . ')';
                $hasClassType = true;
                $groups[] = [array_keys($groupMembers), $display, $member];
                continue;
            }
            $name = $this->parseIdentifier($member);
            $nameLower = strtolower($name);
            if ($nameLower === 'mixed' || $nameLower === 'void' || $nameLower === 'never') {
                $this->fatalError($member, "Type `{$nameLower}` can only be used as a standalone type");
            }
            if ($nameLower === 'bool' || $nameLower === 'false' || $nameLower === 'true') {
                // Zend folds false/true into bool: a union may not repeat the
                // overlap, and naming both literals asks for bool instead.
                if (($nameLower === 'true' && isset($seen['false']))
                    || ($nameLower === 'false' && isset($seen['true']))
                ) {
                    $this->fatalError($member, 'Type contains both `true` and `false`, `bool` must be used instead');
                }
                if ($nameLower === 'bool') {
                    foreach (['false', 'true'] as $literal) {
                        if (isset($seen[$literal])) {
                            $this->fatalError($member, "Duplicate type `{$literal}` is redundant");
                        }
                    }
                } elseif (isset($seen['bool'])) {
                    $this->fatalError($member, "Duplicate type `{$nameLower}` is redundant");
                }
                $addMember($nameLower, $nameLower, $member);
                $builtins[] = $nameLower;
                continue;
            }
            if ($nameLower === 'iterable') {
                // Zend expands iterable to array|Traversable before the
                // redundancy check and reports the overlapping component.
                // The expansion alone does not count as a class type for the
                // object-redundancy rule.
                $addMember('iterable', 'iterable', $member);
                $addMember('array', 'array', $member);
                $addMember('traversable', 'Traversable', $member);
                $classish[] = 'Traversable';
                $builtins[] = 'array';
                continue;
            }
            if (isset($this->zendTypeMap[$nameLower])) {
                $addMember($nameLower, $nameLower, $member);
                if ($nameLower === 'object') {
                    $hasObject = true;
                } else {
                    $builtins[] = $nameLower;
                }
                continue;
            }
            if (in_array($nameLower, ['self', 'parent', 'static'], true)) {
                $addMember($nameLower, $nameLower, $member);
                $classish[] = $nameLower;
                $hasClassType = true;
                continue;
            }
            $resolved = $member instanceof Node\Name\FullyQualified
                ? $member->toString()
                : $this->getNamespacedClassName($name);
            $addMember(strtolower($resolved), $resolved, $member);
            $classish[] = $resolved;
            $hasClassType = true;
            $groups[] = [[strtolower($resolved)], $resolved, $member];
        }

        // Whole-DNF redundancy: Zend compares every pair of intersection
        // groups and plain class members as canonical member sets. An equal
        // set in any member order is a repeat; a strict superset is redundant
        // because it is more restrictive than the smaller type it can never
        // widen: (A&B)|(B&A), (A&B)|A and A|(A&B) are all rejected.
        $groupCount = count($groups);
        for ($i = 0; $i < $groupCount; $i++) {
            for ($j = $i + 1; $j < $groupCount; $j++) {
                [$setI, $displayI] = $groups[$i];
                [$setJ, $displayJ, $nodeJ] = $groups[$j];
                if (count($setI) === count($setJ)) {
                    if (array_diff($setI, $setJ) === []) {
                        $this->fatalError($nodeJ, "Type `{$displayJ}` is redundant with type `{$displayI}`");
                    }
                } elseif (count($setI) > count($setJ)) {
                    if (array_diff($setJ, $setI) === []) {
                        $this->fatalError($groups[$i][2], "Type `{$displayI}` is redundant as it is more restrictive than type `{$displayJ}`");
                    }
                } elseif (array_diff($setI, $setJ) === []) {
                    $this->fatalError($nodeJ, "Type `{$displayJ}` is redundant as it is more restrictive than type `{$displayI}`");
                }
            }
        }

        if ($hasObject && $hasClassType) {
            // `object` already accepts every object: naming a class type
            // (including self/parent/static and DNF groups) beside it is
            // redundant. Zend rejects the whole declared type.
            $order = array_flip(['callable', 'object', 'array', 'string', 'int', 'float', 'bool', 'false', 'true', 'null']);
            $builtins[] = 'object';
            usort($builtins, static fn (string $a, string $b): int => ($order[$a] ?? 99) <=> ($order[$b] ?? 99));
            $typeStr = implode('|', array_merge($classish, $builtins));
            $this->fatalError($type, "Type `{$typeStr}` contains both object and a class type, which is redundant");
        }
    }

    /**
     * @return array<string, string> resolved member names in declaration
     *                               order, keyed by their lowercase form
     */
    private function validateIntersectionTypeDecl(IntersectionType $type): array
    {
        $seen = [];
        foreach ($type->types as $member) {
            $name = $this->parseIdentifier($member);
            $nameLower = strtolower($name);
            if ($nameLower === 'self' || $nameLower === 'parent' || $nameLower === 'static') {
                // Zend never resolves class-scope keywords inside an
                // intersection, bare or as a DNF member of a union — not
                // even in a global closure that could later be bound to a
                // class scope: the scope errors ("no class scope", "no
                // parent") take precedence via the preprocessor's
                // validateClassScopeTypeKeywords, then any surviving
                // keyword is rejected here. buildTypeCheckFromNode only
                // catches the top-level intersection case, so DNF members
                // must be rejected at this layer.
                $this->fatalError($member, "Type `{$nameLower}` cannot be part of an intersection type");
            }
            if (in_array($nameLower, [
                'int', 'float', 'bool', 'false', 'true', 'string', 'array',
                'object', 'mixed', 'null', 'void', 'never', 'callable', 'iterable',
            ], true)) {
                $this->fatalError($member, "Type `{$nameLower}` cannot be part of an intersection type");
            }
            $resolved = $member instanceof Node\Name\FullyQualified
                ? $member->toString()
                : $this->getNamespacedClassName($name);
            $resolvedLower = strtolower($resolved);
            if (isset($seen[$resolvedLower])) {
                $this->fatalError($member, "Duplicate type `{$resolved}` is redundant");
            }
            $seen[$resolvedLower] = $resolved;
        }
        return $seen;
    }
}
