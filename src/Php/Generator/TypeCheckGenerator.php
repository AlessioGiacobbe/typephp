<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Generator;

use PhpAot\Php\ArgInfo;
use PhpParser\Node;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PhpParser\NodeAbstract;

trait TypeCheckGenerator
{
    protected function buildTypeCheckFromNode(NodeAbstract $typeNode): array
    {
        $check = [];
        $typeStr = $this->typeCheckNodeToString($typeNode);

        if ($typeNode instanceof NullableType) {
            $check[] = ['kind' => 'isNull'];
            $innerClause = $this->buildTypeCheckClause($typeNode->type);
            if (!empty($innerClause)) {
                $check[] = count($innerClause) === 1 ? $innerClause[0] : ['kind' => 'allOf', 'types' => $innerClause];
            }
        } elseif ($typeNode instanceof UnionType) {
            foreach ($typeNode->types as $subType) {
                $clause = $this->buildTypeCheckClause($subType);
                if (empty($clause)) {
                    continue;
                }
                $check[] = count($clause) === 1 ? $clause[0] : ['kind' => 'allOf', 'types' => $clause];
            }
        } elseif ($typeNode instanceof IntersectionType) {
            $clause = $this->buildTypeCheckClause($typeNode);
            if (!empty($clause)) {
                $check[] = count($clause) === 1 ? $clause[0] : ['kind' => 'allOf', 'types' => $clause];
            }
        } else {
            return ['check' => [], 'typeStr' => ''];
        }

        if (empty($check)) {
            return ['check' => [], 'typeStr' => $typeStr];
        }

        return ['check' => $check, 'typeStr' => $typeStr];
    }

    private function buildTypeCheckClause(NodeAbstract $typeNode): array
    {
        if ($typeNode instanceof IntersectionType) {
            $clause = [];
            foreach ($typeNode->types as $subType) {
                foreach ($this->buildTypeCheckClause($subType) as $entry) {
                    $clause[] = $entry;
                }
            }
            return $clause;
        }

        $name = $this->parseIdentifier($typeNode);
        $nameLower = strtolower($name);

        if ($nameLower === 'void' || $nameLower === 'never') {
            $this->fatalError($typeNode, "Type '{$nameLower}' cannot be part of a composite type");
        }

        if ($nameLower === 'mixed') {
            return [];
        }

        $entry = match ($nameLower) {
            'int' => ['kind' => 'isInt'],
            'float', 'double' => ['kind' => 'isFloat'],
            'bool' => ['kind' => 'isBool'],
            'string' => ['kind' => 'isString'],
            'array' => ['kind' => 'isArray'],
            'object' => ['kind' => 'isObject'],
            'null' => ['kind' => 'isNull'],
            'true' => ['kind' => 'isTrue'],
            'false' => ['kind' => 'isFalse'],
            'resource' => ['kind' => 'isResource'],
            'callable' => ['kind' => 'callable'],
            'iterable' => ['kind' => 'iterable'],
            default => null,
        };

        if ($entry !== null) {
            return [$entry];
        }

        if ($name === 'self') {
            $class = $this->getFullClassName();
        } elseif ($name === 'parent') {
            $class = $this->classDef->extends ?? '';
        } elseif ($name === 'static') {
            $class = 'static';
        } else {
            $class = $this->getNamespacedClassName($name);
        }

        return $class ? [['kind' => 'instanceof', 'class' => $class]] : [];
    }

    private function typeCheckNodeToString(NodeAbstract $typeNode): string
    {
        if ($typeNode instanceof Node\Identifier) {
            return $typeNode->name;
        }
        if ($typeNode instanceof Node\Name) {
            return $typeNode->toString();
        }
        if ($typeNode instanceof NullableType) {
            return '?' . $this->typeCheckNodeToString($typeNode->type);
        }
        if ($typeNode instanceof UnionType) {
            $parts = [];
            foreach ($typeNode->types as $type) {
                $parts[] = $this->typeCheckNodeToString($type);
            }
            sort($parts);
            return implode('|', $parts);
        }
        if ($typeNode instanceof IntersectionType) {
            $parts = [];
            foreach ($typeNode->types as $type) {
                $parts[] = $this->typeCheckNodeToString($type);
            }
            sort($parts);
            return implode('&', $parts);
        }

        return $this->printer->prettyPrint([$typeNode]);
    }

    protected function genSingleTypeCondition(string $varName, array $entry): string
    {
        $v = $varName;
        return match ($entry['kind']) {
            'isInt' => $v . '.isInt()',
            'isFloat' => $v . '.isFloat()',
            'isBool' => $v . '.isBool()',
            'isString' => $v . '.isString()',
            'isArray' => $v . '.isArray()',
            'isObject' => $v . '.isObject()',
            'isNull' => $v . '.isNull()',
            'isTrue' => $v . '.isTrue()',
            'isFalse' => $v . '.isFalse()',
            'isResource' => $v . '.isResource()',
            'callable' => $v . '.isCallable()',
            'iterable' => '(' . $v . '.isArray() || (' . $v . '.isObject() && php::instanceOf(' . $v . ', zend_ce_traversable)))',
            'allOf' => $this->genAllOfTypeCondition($varName, $entry['types']),
            'instanceof' => $entry['class'] === 'static'
                ? '(' . $v . '.isObject() && php::instanceOf(' . $v . ', php_get_called_ce(this_)))'
                : '(' . $v . '.isObject() && php::instanceOf(' . $v . ', ' . $this->getClassEntryPtr($entry['class']) . '))',
            default => '',
        };
    }

    private function genAllOfTypeCondition(string $varName, array $types): string
    {
        $conditions = [];
        foreach ($types as $type) {
            $cond = $this->genSingleTypeCondition($varName, $type);
            if ($cond !== '') {
                $conditions[] = $cond;
            }
        }

        if (empty($conditions)) {
            return '';
        }

        return '(' . implode(' && ', $conditions) . ')';
    }

    protected function genUnionParamCheck(ArgInfo $argInfo, int $argIndex): string
    {
        if (empty($argInfo->typeCheck)) {
            return '';
        }

        $varName = $argInfo->name;
        if ($argInfo->variadic) {
            return $this->genUnionVariadicParamCheck($argInfo, $argIndex);
        }

        $conditions = [];
        foreach ($argInfo->typeCheck as $entry) {
            $cond = $this->genSingleTypeCondition($varName, $entry);
            if ($cond !== '') {
                $conditions[] = $cond;
            }
        }
        if (empty($conditions)) {
            return '';
        }

        $orExpr = implode(' || ', $conditions);
        $msgExpr = $this->genUnionParamTypeErrorExpr($argInfo, $varName, (string) ($argIndex + 1));

        $code = $this->getIndent() . 'if (UNEXPECTED(!(' . $orExpr . '))) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . 'php::throwException(zend_ce_type_error, (' . $msgExpr . ').toCString());' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    protected function genUnionVariadicParamCheck(ArgInfo $argInfo, int $argIndex): string
    {
        $valueVar = $this->genTmpVarName();
        $iterVar = $this->genTmpVarName();
        $argNoVar = $this->genTmpVarName();

        $conditions = [];
        foreach ($argInfo->typeCheck as $entry) {
            $cond = $this->genSingleTypeCondition($valueVar, $entry);
            if ($cond !== '') {
                $conditions[] = $cond;
            }
        }
        if (empty($conditions)) {
            return '';
        }

        $orExpr = implode(' || ', $conditions);
        $msgExpr = $this->genUnionParamTypeErrorExpr($argInfo, $valueVar, $argNoVar);

        $code = $this->getIndent() . 'for (auto ' . $iterVar . ' = ' . $argInfo->name . '.begin(); ' . $iterVar . ' != ' . $argInfo->name . '.end(); ++' . $iterVar . ') {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . self::TYPE_VAR . ' ' . $valueVar . ' = ' . $iterVar . '.value();' . PHP_EOL;
        $code .= $this->getIndent() . self::TYPE_INT . ' ' . $argNoVar . ' = ' . ($argIndex + 1) . ' + ' . $iterVar . '.index();' . PHP_EOL;
        $code .= $this->getIndent() . 'if (UNEXPECTED(!(' . $orExpr . '))) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . 'php::throwException(zend_ce_type_error, (' . $msgExpr . ').toCString());' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

    protected function genUnionParamTypeErrorExpr(ArgInfo $argInfo, string $valueExpr, string $argNoExpr): string
    {
        $fnName = $this->functionDef->getNamespacedName();
        return 'php::concat({'
            . 'php::Str(' . $this->genCharPtr($fnName . '(): Argument #', true) . '), '
            . 'php::toString(' . $argNoExpr . '), '
            . 'php::Str(' . $this->genCharPtr(' ($' . $argInfo->name . ') must be of type ', true) . '), '
            . 'php::Str(' . $this->genCharPtr($argInfo->typeStr, true) . '), '
            . 'php::Str(", "), '
            . $valueExpr . '.typeStr(), '
            . 'php::Str(" given")'
            . '})';
    }

    protected function genUnionReturnCheck(string $varName): string
    {
        $typeCheck = $this->functionDef->returnTypeCheck;
        if (empty($typeCheck)) {
            return '';
        }

        $conditions = [];
        foreach ($typeCheck as $entry) {
            $cond = $this->genSingleTypeCondition($varName, $entry);
            if ($cond !== '') {
                $conditions[] = $cond;
            }
        }
        if (empty($conditions)) {
            return '';
        }

        $orExpr = implode(' || ', $conditions);
        $fnName = $this->functionDef->getNamespacedName();
        $typeStr = $this->functionDef->returnTypeStr;

        $msgExpr = 'php::concat(php::concat(php::Str(' . $this->genCharPtr($fnName, true) . ' "(): Return value must be of type " '
                 . $this->genCharPtr($typeStr, true) . ' ", "), ' . $varName . '.typeStr()), php::Str(" given"))';

        $code = $this->getIndent() . 'if (UNEXPECTED(!(' . $orExpr . '))) {' . PHP_EOL;
        $this->indentLevel++;
        $code .= $this->getIndent() . 'php::throwException(zend_ce_type_error, (' . $msgExpr . ').toCString());' . PHP_EOL;
        $this->indentLevel--;
        $code .= $this->getIndent() . '}' . PHP_EOL;

        return $code;
    }

}
