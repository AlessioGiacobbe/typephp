<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Parser;

use PhpAot\Php\Symbol;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait StdContainerParser
{
    protected function isStdContainer(string $var): bool
    {
        return $this->hasLocalVar($var) and in_array($this->getVarType($var), [
                self::TYPE_STD_ARRAY,
                self::TYPE_STD_VECTOR,
                self::TYPE_STD_MAP,
                self::TYPE_STD_UNORDERED_MAP,
            ], true);
    }

    protected function isStdArray(string $var): bool
    {
        return $this->hasLocalVar($var) and $this->getVarType($var) === self::TYPE_STD_ARRAY;
    }

    protected function isStdVector(string $var): bool
    {
        return $this->hasLocalVar($var) and $this->getVarType($var) === self::TYPE_STD_VECTOR;
    }

    protected function isStdMap(string $var): bool
    {
        return $this->hasLocalVar($var) and $this->getVarType($var) === self::TYPE_STD_MAP;
    }

    protected function isStdUnorderedMap(string $var): bool
    {
        return $this->hasLocalVar($var) and $this->getVarType($var) === self::TYPE_STD_UNORDERED_MAP;
    }

    protected function getStdTypeKey(array $info): string
    {
        $parts = [
            'kind=' . $info['kind'],
            'decl=' . $info['decl'],
            'type=' . $info['type'],
            'class=' . ($info['class'] ?? ''),
        ];
        if (isset($info['keyType'])) {
            $parts[] = 'keyType=' . $info['keyType'];
        }
        return implode(';', $parts);
    }

    protected function addStdTypeId(array $info): array
    {
        $info['typeId'] = $this->registerStdType($this->getStdTypeKey($info));
        return $info;
    }

    protected function getStdContainerVarInfo(string $var): array
    {
        if ($this->isStdArray($var)) {
            return $this->context->stdArrays[$var];
        }
        return $this->context->stdContainers[$var];
    }

    protected function getStdArrayDecl(string $type, array $sizes): string
    {
        $decl = str_repeat(self::TYPE_STD_ARRAY . '<', count($sizes));
        $decl .= $type;
        for ($i = count($sizes) - 1; $i >= 0; $i--) {
            $decl .= ', ' . $sizes[$i] . '>';
        }
        return $decl;
    }

    protected function getStdValueTypeBytes(string $type): int
    {
        return match ($type) {
            self::TYPE_BOOL => 1,
            self::TYPE_INT, self::TYPE_FLOAT => 8,
            default => 16,
        };
    }

    protected function getNestedStdArrayInfo(array $info, int $accessLevel): ?array
    {
        $sizes = array_reverse($info['sizes']);
        if ($accessLevel >= count($sizes)) {
            return null;
        }

        $nestedSizes = array_slice($sizes, $accessLevel);
        return [
            'kind' => 'array',
            'decl' => $this->getStdArrayDecl($info['type'], $nestedSizes),
            'type' => $info['type'],
            'class' => $info['class'],
            'sizes' => array_reverse($nestedSizes),
            'bytes' => array_product($nestedSizes) * $this->getStdValueTypeBytes($info['type']),
        ];
    }

    protected function getStdArrayDimFetchContainerInfo(Expr\ArrayDimFetch $expr): ?array
    {
        if (!$expr->hasAttribute('stdArrayDimFetch')) {
            $this->parseStdArrayDimFetch($expr);
        }
        $attr = $expr->getAttribute('stdArrayDimFetch');
        return $this->getNestedStdArrayInfo($this->context->stdArrays[$attr['var']], $attr['accessLevel']);
    }

    protected function isSameStdContainerInfo(array $leftInfo, array $rightInfo): bool
    {
        return $this->getStdTypeKey($leftInfo) === $this->getStdTypeKey($rightInfo);
    }

    protected function getStdContainerExprInfo(NodeAbstract $expr): ?array
    {
        if ($this->isVarExpr($expr)) {
            $var = $this->parseVariable($expr);
            if ($this->isStdContainer($var)) {
                return $this->getStdContainerVarInfo($var);
            }
            return null;
        }
        if ($this->isArrayDimFetch($expr) and $this->isStdArrayExpr($expr)) {
            return $this->getStdArrayDimFetchContainerInfo($expr);
        }
        return null;
    }

    protected function parseStdContainerCopyExpr(NodeAbstract $expr): string
    {
        if ($this->isVarExpr($expr)) {
            return $this->parseVariable($expr);
        }
        if ($this->isArrayDimFetch($expr) and $this->isStdArrayExpr($expr)) {
            return $this->parseStdArrayDimFetch($expr);
        }
        return $this->parseExpr($expr);
    }

    protected function isStdArrayExpr(Expr\ArrayDimFetch $expr): bool
    {
        $info = $this->getStdArrayInfo($expr);
        return $info !== null;
    }

    protected function isStdContainerExpr(Expr\ArrayDimFetch $expr): bool
    {
        return $this->isStdArrayExpr($expr) || $this->getStdContainerInfo($expr) !== null;
    }

    protected function fillStdArray(Expr\StaticCall $expr): string
    {
        if (!$this->isVarExpr($expr->args[0]->value) or !$this->isStdArray($this->parseIdentifier($expr->args[0]->value))) {
            $this->fatalError($expr, 'fill() only support std::array');
        }
        $array = $this->parseIdentifier($expr->args[0]->value);
        $info = $this->context->stdArrays[$array];
        $value = $this->convertStdValueExpr($info, $expr->args[1]->value);
        return "{$array}.fill({$value})";
    }

    protected function getStdArrayInfo(Expr\ArrayDimFetch $expr): ?array
    {
        $tmp = $expr->var;
        while (true) {
            if ($this->isArrayDimFetch($tmp)) {
                $tmp = $tmp->var;
            } elseif ($this->isVarExpr($tmp) and $this->isStdArray($this->parseVariable($tmp))) {
                return $this->context->stdArrays[$this->parseVariable($tmp)];
            } else {
                return null;
            }
        }
    }

    protected function getStdContainerInfo(Expr\ArrayDimFetch $expr): ?array
    {
        $tmp = $expr->var;
        while (true) {
            if ($this->isArrayDimFetch($tmp)) {
                $tmp = $tmp->var;
            } elseif ($this->isVarExpr($tmp)) {
                $var = $this->parseVariable($tmp);
                if ($this->isStdVector($var) || $this->isStdMap($var) || $this->isStdUnorderedMap($var)) {
                    return $this->context->stdContainers[$var];
                }
                return null;
            } else {
                return null;
            }
        }
    }

    protected function parseStdArrayAssign(NodeAbstract $left, NodeAbstract $right): string
    {
        $info = $this->getStdArrayInfo($left);
        $arrayDimFetch = $this->parseStdArrayDimFetch($left);
        $attr = $left->getAttribute('stdArrayDimFetch');
        if ($attr['accessLevel'] < $attr['totalLevel']) {
            $leftInfo = $this->getNestedStdArrayInfo($info, $attr['accessLevel']);
            $rightInfo = $this->getStdContainerExprInfo($right);
            if ($rightInfo !== null and $this->isSameStdContainerInfo($leftInfo, $rightInfo)) {
                return $arrayDimFetch . ' = ' . $this->parseStdContainerCopyExpr($right);
            }
            $this->fatalError($right, 'Cannot assign non-matching value to nested std::array');
        }
        return $arrayDimFetch . ' = ' . $this->convertStdValueExpr($info, $right);
    }

    protected function parseStdContainerAssign(Expr\ArrayDimFetch $left, NodeAbstract $right): string
    {
        if ($this->isStdArrayExpr($left)) {
            return $this->parseStdArrayAssign($left, $right);
        }

        $info = $this->getStdContainerInfo($left);
        if ($info['kind'] === 'vector' && $left->dim === null) {
            if (!$this->isVarExpr($left->var)) {
                $this->fatalError($left, 'std::vector append only supports a vector variable');
            }
            $vector = $this->parseVariable($left->var);
            return $vector . '.push_back(' . $this->convertStdValueExpr($info, $right) . ')';
        }
        if ($left->dim === null) {
            $this->fatalError($left, 'std map expects a key');
        }

        return $this->parseStdContainerOffsetSet($left, $this->convertStdValueExpr($info, $right));
    }

    protected function parseStdArrayAssignOp(Expr\AssignOp $expr, string $op): string
    {
        $binaryOp = $this->removeAssignOp($op);
        if ($binaryOp === '.') {
            $this->fatalError($expr, 'Cannot concat string to std::array');
        }

        $info = $this->getStdArrayInfo($expr->var);
        $arrayDimFetch = $this->parseStdArrayDimFetch($expr->var);
        $attr = $expr->var->getAttribute('stdArrayDimFetch');
        if ($attr['accessLevel'] < $attr['totalLevel']) {
            $this->fatalError($expr, 'Cannot use assign operator on nested std::array');
        }
        return $arrayDimFetch . ' ' . $binaryOp . '= ' . $this->convertExprFromType($info['type'], $this->parseExpr($expr->expr));
    }

    protected function parseStdContainerAssignOp(Expr\AssignOp $expr, string $op): string
    {
        if ($this->isStdArrayExpr($expr->var)) {
            return $this->parseStdArrayAssignOp($expr, $op);
        }

        $binaryOp = $this->removeAssignOp($op);
        if ($binaryOp === '.') {
            $this->fatalError($expr, 'Cannot concat string to std container');
        }

        $info = $this->getStdContainerInfo($expr->var);
        $containerDimFetch = $this->parseStdContainerDimFetch($expr->var);
        return $containerDimFetch . ' ' . $binaryOp . '= ' . $this->convertExprFromType($info['type'], $this->parseExpr($expr->expr));
    }

    protected function parseStdArrayDimFetch(Expr\ArrayDimFetch $expr): string
    {
        $tmp = $expr;
        $dims = [];
        $info = $this->getStdArrayInfo($expr);

        while (true) {
            if ($this->isArrayDimFetch($tmp)) {
                if ($tmp->dim === null) {
                    $this->fatalError($tmp, 'std::array expects an index');
                }
                $dims[] = $tmp->dim;
                $tmp = $tmp->var;
            } else {
                break;
            }
        }
        if (!$this->isVarExpr($tmp)) {
            $this->fatalError($expr, 'std::array expects a variable');
        }

        $dims = array_reverse($dims);
        $sizes = array_reverse($info['sizes']);
        if (count($dims) > count($sizes)) {
            $this->fatalError($expr, 'std::array access level exceeds array dimensions');
        }

        $nesting = [$this->parseVariable($tmp)];
        foreach ($dims as $level => $dim) {
            $size = $sizes[$level];
            if ($this->isScalarInt($dim)) {
                if ($dim->value < 0 || $dim->value >= $size) {
                    $this->fatalError($dim, "std::array index out of bounds: index {$dim->value}, size {$size}");
                }
            }
            $index = $this->parseExpr($dim);
            $nesting[] = '[' . Symbol::safeIndex($this->convertIntExpr($index), $size) . ']';
        }
        $expr->setAttribute('stdArrayDimFetch', ['var' => $nesting[0], 'accessLevel' => count($dims), 'totalLevel' => count($sizes)]);

        return implode('', $nesting);
    }

    protected function parseStdContainerDimFetch(Expr\ArrayDimFetch $expr): string
    {
        if ($this->isStdArrayExpr($expr)) {
            return $this->parseStdArrayDimFetch($expr);
        }

        $info = $this->getStdContainerInfo($expr);
        $tmp = $expr;
        $dims = [];
        while (true) {
            if ($this->isArrayDimFetch($tmp)) {
                $dims[] = $tmp->dim;
                $tmp = $tmp->var;
            } else {
                break;
            }
        }
        if (!$this->isVarExpr($tmp)) {
            $this->fatalError($expr, 'std container expects a variable');
        }
        if (count($dims) !== 1) {
            $this->fatalError($expr, 'Nested std::vector/std::map/std::unordered_map access is not supported');
        }
        $dim = $dims[0];
        if ($dim === null) {
            $this->fatalError($expr, 'std container expects an index');
        }

        $container = $this->parseVariable($tmp);
        $index = $this->parseExpr($dim);
        $key = $info['kind'] === 'vector' ? $this->convertIntExpr($index) : $this->convertStdContainerKey($info, $index);
        $access = $container . '.offsetGet(' . $key . ')';
        $expr->setAttribute('stdContainerDimFetch', ['var' => $container, 'accessLevel' => 1, 'totalLevel' => 1]);

        return $access;
    }

    protected function parseStdContainerOffsetSet(Expr\ArrayDimFetch $expr, string $value): string
    {
        $info = $this->getStdContainerInfo($expr);
        if ($expr->dim === null) {
            $this->fatalError($expr, 'std container expects an index');
        }
        if (!$this->isVarExpr($expr->var)) {
            $this->fatalError($expr, 'std container expects a variable');
        }
        $container = $this->parseVariable($expr->var);
        $indexExpr = $this->parseExpr($expr->dim);
        $index = $info['kind'] === 'vector' ? $this->convertIntExpr($indexExpr) : $this->convertStdContainerKey($info, $indexExpr);
        return $container . '.offsetSet(' . $index . ', ' . $value . ')';
    }

    protected function convertStdContainerKey(array $info, string $index): string
    {
        if ($info['keyType'] === self::TYPE_STR) {
            return $this->convertStringExpr($index);
        }
        return $this->convertIntExpr($index);
    }

    protected function parseStdNativeType(NodeAbstract $expr, string $owner): string
    {
        if (!$this->isClassConstFetch($expr)) {
            $this->fatalError($expr, "{$owner} expects a native_types class constant");
        }
        if (!$this->isNameExpr($expr->class) || !$this->isIdExpr($expr->name) || $expr->class->toString() !== 'native_types') {
            $this->fatalError($expr, "An incorrect `{$owner}` definition");
        }
        return match ($expr->name->name) {
            'type_int' => self::TYPE_INT,
            'type_float' => self::TYPE_FLOAT,
            'type_bool' => self::TYPE_BOOL,
            default => $this->fatalError($expr, "An incorrect `{$owner}` definition"),
        };
    }

    protected function parseStdValueTypeInfo(NodeAbstract $expr, string $owner): array
    {
        if (!$this->isClassConstFetch($expr)) {
            $this->fatalError($expr, "{$owner} expects a native_types or complex_types class constant");
        }
        if (!$this->isNameExpr($expr->class) || !$this->isIdExpr($expr->name)) {
            $this->fatalError($expr, "An incorrect `{$owner}` definition");
        }
        if ($expr->class->toString() === 'native_types') {
            return ['type' => $this->parseStdNativeType($expr, $owner), 'class' => null];
        }
        if ($expr->class->toString() === 'complex_types') {
            return [
                'type' => match ($expr->name->name) {
                    'type_str', 'type_string' => self::TYPE_STR,
                    'type_array' => self::TYPE_ARRAY,
                    'type_object' => self::TYPE_OBJECT,
                    'type_any', 'type_var', 'type_variant' => self::TYPE_VAR,
                    default => $this->fatalError($expr, "An incorrect `{$owner}` definition"),
                },
                'class' => null,
            ];
        }
        if ($expr->name->name !== 'class') {
            $this->fatalError($expr, "{$owner} class value only supports ClassName::class");
        }
        $class = $this->parseStdClassValueType($expr, $owner);
        return ['type' => self::TYPE_OBJECT, 'class' => $class];
    }

    protected function parseStdValueType(NodeAbstract $expr, string $owner): string
    {
        return $this->parseStdValueTypeInfo($expr, $owner)['type'];
    }

    protected function parseStdClassValueType(Expr\ClassConstFetch $expr, string $owner): string
    {
        $class = $this->parseIdentifier($expr->class);
        if ($class === 'static') {
            $this->fatalError($expr, "{$owner} class value does not support static::class");
        }
        if ($class === 'self' || $class === 'this_') {
            if (!$this->classDef) {
                $this->fatalError($expr, "{$owner} class value cannot use self::class outside class scope");
            }
            $class = $this->getNamespacedClassName($this->class);
        } elseif ($class === 'parent') {
            if (!$this->classDef || !$this->classDef->extends) {
                $this->fatalError($expr, "{$owner} class value cannot use parent::class because current class does not extend any class");
            }
            $class = $this->getNamespacedClassName('\\' . $this->classDef->extends);
        } else {
            $class = $this->getNamespacedClassName($class);
        }
        if ($this->hasInterface($class)) {
            $this->fatalError($expr, "{$owner} class value cannot use interface `{$class}`");
        }
        if ($this->isAbstractClass($class)) {
            $this->fatalError($expr, "{$owner} class value cannot use abstract class `{$class}`");
        }
        return $class;
    }

    protected function convertStdValueExpr(array $info, NodeAbstract $expr): string
    {
        $valueExpr = $this->parseExpr($expr);
        $class = $info['class'] ?? null;
        if ($class === null) {
            return $this->convertExprFromType($info['type'], $valueExpr);
        }
        $rightClass = $this->detectClassOfExpr($expr);
        if ($rightClass !== '') {
            if ($rightClass !== $class) {
                $this->fatalError($expr, "Cannot assign object of class `{$rightClass}` to std container value of class `{$class}`");
            }
        }

        return 'php::toObject(' . $valueExpr . ', ' . $this->getClassEntryPtr($class) . ', true)';
    }

    protected function parseStdUnsafeCastAssign(string $var, Expr\StaticCall $expr): string
    {
        if (count($expr->args) !== 2) {
            $this->fatalError($expr, 'std::unsafe_cast() expects two arguments');
        }
        $typeExpr = $expr->args[0]->value;
        if (!$this->isStaticCall($typeExpr) || !$this->isNameExpr($typeExpr->class) || !$this->isIdExpr($typeExpr->name) || $typeExpr->class->toString() !== 'std') {
            $this->fatalError($expr->args[0]->value, 'std::unsafe_cast() expects first argument to be a std container type expression');
        }
        $containerType = $typeExpr->name->toString();
        if (!in_array($containerType, ['array', 'vector', 'map', 'unordered_map'], true)) {
            $this->fatalError($expr->args[0]->value, 'std::unsafe_cast() expects first argument to be a std container type expression');
        }
        if (!$this->isVarExpr($expr->args[1]->value)) {
            $this->fatalError($expr->args[1]->value, 'std::unsafe_cast() expects second argument to be an UnsafePtr variable');
        }
        $unsafePtr = $this->parseVariable($expr->args[1]->value);
        if (!$this->hasVar($unsafePtr)) {
            $this->fatalError($expr->args[1]->value, 'Undefined variable `$' . $unsafePtr . '`');
        }
        if (!$this->isUnsafePtrParameter($unsafePtr)) {
            $this->fatalError($expr->args[1]->value, 'std::unsafe_cast() expects second argument to be an UnsafePtr parameter');
        }

        if ($containerType === 'array') {
            $this->addLocalVar($var, self::TYPE_STD_ARRAY);
            $this->parseStdArray($var, $typeExpr);
            $this->context->stdArrays[$var]['unsafePtr'] = $unsafePtr;
            return '// php_unsafe_cast<' . $this->context->stdArrays[$var]['decl'] . '>(' . $unsafePtr . ')';
        }

        if ($containerType === 'vector') {
            $this->addLocalVar($var, self::TYPE_STD_VECTOR);
            $this->parseStdVector($var, $typeExpr);
        } elseif ($containerType === 'map') {
            $this->addLocalVar($var, self::TYPE_STD_MAP);
            $this->parseStdMap($var, $typeExpr);
        } else {
            $this->addLocalVar($var, self::TYPE_STD_UNORDERED_MAP);
            $this->parseStdUnorderedMap($var, $typeExpr);
        }
        $this->context->stdContainers[$var]['unsafePtr'] = $unsafePtr;
        return '// php_unsafe_cast<' . $this->context->stdContainers[$var]['decl'] . '>(' . $unsafePtr . ')';
    }

    protected function parseStdMapKeyType(NodeAbstract $expr, string $owner): string
    {
        if (!$this->isClassConstFetch($expr) || !$this->isNameExpr($expr->class) || !$this->isIdExpr($expr->name)) {
            $this->fatalError($expr, "{$owner} expects a native_types or complex_types class constant");
        }
        if ($expr->class->toString() === 'native_types' && $expr->name->name === 'type_int') {
            return self::TYPE_INT;
        }
        if ($expr->class->toString() === 'complex_types' && in_array($expr->name->name, ['type_string', 'type_str'], true)) {
            return self::TYPE_STR;
        }
        $this->fatalError($expr, "{$owner} key only supports native_types::type_int, complex_types::type_string or complex_types::type_str");
    }

    protected function getStdMapDecl(string $containerType, string $keyType, string $valueType): string
    {
        $decl = $containerType . '<' . $valueType;
        if ($keyType === self::TYPE_STR) {
            $decl .= ', ' . self::TYPE_STR;
        }
        return $decl . '>';
    }

    protected function parseStdArray(string $var, Expr\StaticCall $expr): string
    {
        $tmp = $expr;
        $nesting = [];
        $totalBytes = 0;

        while (true) {
            if (count($tmp->args) !== 2) {
                $this->fatalError($tmp, 'std::array() expects two arguments');
            }
            if (!$this->isScalarInt($tmp->args[1]->value)) {
                $this->fatalError($tmp, 'std::array() expects second argument to be an integer');
            }
            $byte = 0;
            $size = $tmp->args[1]->value->value;
            $nesting[] = $size;
            $typeExpr = $tmp->args[0]->value;
            if ($this->isClassConstFetch($typeExpr)) {
                $typeInfo = $this->parseStdValueTypeInfo($typeExpr, 'std::array');
                $type = $typeInfo['type'];
                $byte = $this->getStdValueTypeBytes($type);
                break;
            }
            if ($this->isStaticCall($typeExpr)) {
                $tmp = $typeExpr;
                if (!$this->isNameExpr($tmp->class) || !$this->isIdExpr($tmp->name) || $tmp->class->toString() !== 'std' || $tmp->name->toString() !== 'array') {
                    $this->fatalError($tmp, 'An incorrect `std::array` definition');
                }
            } else {
                $this->fatalError($tmp, 'std::array() expects first argument to be a class constant');
            }
        }
        $totalBytes = array_product($nesting) * $byte;

        $decl = $this->getStdArrayDecl($type, $nesting);
        $this->context->stdArrays[$var] = $this->addStdTypeId([
            'kind' => 'array',
            'decl' => $decl,
            'type' => $type,
            'class' => $typeInfo['class'],
            'sizes' => array_reverse($nesting),
            'bytes' => $totalBytes,
        ]);
        return '// ' . $decl;
    }

    protected function parseStdVector(string $var, Expr\StaticCall $expr): string
    {
        if (count($expr->args) < 1 || count($expr->args) > 2) {
            $this->fatalError($expr, 'std::vector() expects one or two arguments');
        }
        $typeInfo = $this->parseStdValueTypeInfo($expr->args[0]->value, 'std::vector');
        $type = $typeInfo['type'];
        $size = null;
        if (count($expr->args) === 2) {
            if (!$this->isScalarInt($expr->args[1]->value)) {
                $this->fatalError($expr, 'std::vector() expects second argument to be an integer');
            }
            $size = $expr->args[1]->value->value;
        }
        $decl = self::TYPE_STD_VECTOR . '<' . $type . '>';
        $this->context->stdContainers[$var] = $this->addStdTypeId([
            'kind' => 'vector',
            'decl' => $decl,
            'type' => $type,
            'class' => $typeInfo['class'],
            'size' => $size,
        ]);
        return '// ' . $decl;
    }

    protected function parseStdMap(string $var, Expr\StaticCall $expr): string
    {
        if (count($expr->args) !== 2) {
            $this->fatalError($expr, 'std::map() expects two arguments');
        }
        $keyType = $this->parseStdMapKeyType($expr->args[0]->value, 'std::map');
        $valueTypeInfo = $this->parseStdValueTypeInfo($expr->args[1]->value, 'std::map');
        $valueType = $valueTypeInfo['type'];
        $decl = $this->getStdMapDecl(self::TYPE_STD_MAP, $keyType, $valueType);
        $this->context->stdContainers[$var] = $this->addStdTypeId([
            'kind' => 'map',
            'decl' => $decl,
            'type' => $valueType,
            'class' => $valueTypeInfo['class'],
            'keyType' => $keyType,
        ]);
        return '// ' . $decl;
    }

    protected function parseStdUnorderedMap(string $var, Expr\StaticCall $expr): string
    {
        if (count($expr->args) !== 2) {
            $this->fatalError($expr, 'std::unordered_map() expects two arguments');
        }
        $keyType = $this->parseStdMapKeyType($expr->args[0]->value, 'std::unordered_map');
        $valueTypeInfo = $this->parseStdValueTypeInfo($expr->args[1]->value, 'std::unordered_map');
        $valueType = $valueTypeInfo['type'];
        $decl = $this->getStdMapDecl(self::TYPE_STD_UNORDERED_MAP, $keyType, $valueType);
        $this->context->stdContainers[$var] = $this->addStdTypeId([
            'kind' => 'unordered_map',
            'decl' => $decl,
            'type' => $valueType,
            'class' => $valueTypeInfo['class'],
            'keyType' => $keyType,
        ]);
        return '// ' . $decl;
    }
}
