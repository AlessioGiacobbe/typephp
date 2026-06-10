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
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType;
use PhpParser\NodeAbstract;

trait TypeCheckGenerator
{
    protected function buildTypeCheckFromNode(NodeAbstract $typeNode): array
    {
        $check = [];
        $names = [];

        if ($typeNode instanceof NullableType) {
            $subTypes = [$typeNode->type];
            $isNullable = true;
        } elseif ($typeNode instanceof UnionType) {
            $subTypes = $typeNode->types;
            $isNullable = false;
        } else {
            return ['check' => [], 'typeStr' => ''];
        }

        foreach ($subTypes as $subType) {
            $name = $this->parseIdentifier($subType);
            $nameLower = strtolower($name);

            if ($nameLower === 'void' or $nameLower === 'never') {
                $this->fatalError($subType, "Type '{$nameLower}' cannot be part of a union type");
            }

            if ($nameLower === 'mixed') {
                // mixed accepts everything — don't add any check
                $names[] = $name;
                continue;
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
                $check[] = $entry;
            } else {
                // Class/interface type
                if ($name === 'self') {
                    $class = $this->getFullClassName();
                } elseif ($name === 'parent') {
                    $class = $this->classDef->extends ?? '';
                } elseif ($name === 'static') {
                    $class = 'static';
                } else {
                    $class = $this->getNamespacedClassName($name);
                }
                if ($class) {
                    $check[] = ['kind' => 'instanceof', 'class' => $class];
                }
            }
            $names[] = $name;
        }

        if ($isNullable) {
            // NullableType: prepend null to both check array and typeStr
            array_unshift($check, ['kind' => 'isNull']);
            $typeStr = '?' . implode('|', $names);
        } else {
            $typeStr = implode('|', $names);
        }

        if (empty($check)) {
            return ['check' => [], 'typeStr' => $typeStr];
        }

        return ['check' => $check, 'typeStr' => $typeStr];
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
            'instanceof' => $entry['class'] === 'static'
                ? '(' . $v . '.isObject() && php::instanceOf(' . $v . ', php_get_called_ce(this_)))'
                : '(' . $v . '.isObject() && php::instanceOf(' . $v . ', ' . $this->getClassEntryPtr($entry['class']) . '))',
            default => '',
        };
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
