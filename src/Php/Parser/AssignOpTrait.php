<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Parser;

use PhpAot\Php\Resolver\PropertyWriteTarget;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Variable;
use PhpParser\NodeAbstract;

trait AssignOpTrait
{
    protected function parseAssignArrayDim(NodeAbstract $left, NodeAbstract $right): string
    {
        if ($this->isPropertyFetch($left)) {
            return $this->parseAssignPropertyArrayDim($left, $right);
        }
        if ($this->isVarExpr($left->var) && $left->var->name === 'GLOBALS') {
            $target = $this->parseGlobalsArrayDimFetch($left);
            $value = $this->parseExprAsValue($right);
            $tmp = $this->genTmpVarName();
            $this->addLocalVar($tmp, self::TYPE_VAR);
            return '((' . $tmp . ' = ' . $value . ', ' . $target . ' = ' . $tmp . '), ' . $tmp . ')';
        }
        $array              = $this->parseWritableIdentifier($left->var);
        $code               = '';
        if (!$this->hasVar($array) and $this->isVarExpr($left->var)) {
            $this->addLocalVar($array, self::TYPE_ARRAY);
        }

        $value = $this->parseExprAsValue($right);

        $tmp = $this->genTmpVarName();
        $this->addLocalVar($tmp, self::TYPE_VAR);

        if ($left->dim === null) {
            return $code . '((' . $tmp . ' = ' . $value . ', ' . "{$array}.offsetSet(" . self::VALUE_NULL . ", {$tmp})" . '), ' . $tmp . ')';
        }
        $dim = $this->parseIdentifier($left->dim);

        return $code . '((' . $tmp . ' = ' . $value . ', ' . "{$array}.offsetSet({$dim}, {$tmp})" . '), ' . $tmp . ')';
    }

    protected function parseAssignPropertyFetch(NodeAbstract $left, NodeAbstract $right, ?PropertyWriteTarget $target = null): string
    {
        if ($target !== null) {
            $this->assertCanAssignPropertyWrite($target, $right);
        }

        $rightExpr = $this->parseExprAsValue($right);
        if ($target !== null) {
            $rightExpr = $this->wrapPropertyWriteTypeCheck($target, $right, $rightExpr);
        } else {
            $rightExpr = $this->wrapObjectPropertyAssignTypeCheck($left, $right, $rightExpr);
        }

        $tmp = $this->genTmpVarName();
        $this->addLocalVar($tmp, self::TYPE_VAR);
        // Comma expression: store RHS → execute side effect → evaluate to stored value
        return '((' . $tmp . ' = ' . $rightExpr . ', ' . $this->emitDynamicPropertyFetchWrite($left, $tmp, $target) . '), ' . $tmp . ')';
    }

    protected function parseRightAssociativeAssign(NodeAbstract $left, Expr\Assign $right): string
    {
        $chain[] = $left;
        $next    = $right;
        while ($this->isAssignExpr($next)) {
            $var = $next->var;
            $chain[] = $var;
            $next    = $next->expr;
        }
        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, self::TYPE_VAR);

        // 翻转赋值链
        $chain = array_reverse($chain);
        $list  = [];

        $list[] = $tmpVar . ' = ' . $this->parseExpr($next);
        $rightVar  = new Variable($tmpVar);
        foreach ($chain as $var) {
            $list[] = $this->parseAssignFinally($var, $rightVar);
        }

        return '(' . implode(', ', $list) . ')';
    }

    protected function parseAssign(Expr\Assign $v): string
    {
        $left  = $v->var;
        $right = $v->expr;
        if ($this->isAssignExpr($right)) {
            return $this->parseRightAssociativeAssign($left, $right);
        }
        return $this->parseAssignFinally($left, $right);
    }

    protected function parseAssignToList(Expr $left, Expr $right): string
    {
        $items = $left->items;
        $code  = '{';
        $this->indentLevel++;
        $tmpVar = $this->genTmpVarName();
        $this->addLocalVar($tmpVar, self::TYPE_VAR);
        $code .= $this->getIndent() . $tmpVar . ' = ' . $this->parseExpr($right) . '; ';
        foreach ($items as $k => $item) {
            if (!$item) {
                continue;
            }
            if ($item instanceof ArrayItem) {
                $key = $item->key ? $this->parseArrayKey($item->key) : (string) $k;
                if ($item->value instanceof Expr\List_) {
                    $nestedTmp = $this->genTmpVarName();
                    $this->addLocalVar($nestedTmp, self::TYPE_ARRAY);
                    $code .= "{$nestedTmp} = {$tmpVar}.item({$key}); ";
                    $code .= $this->parseAssignToList($item->value, new Variable($nestedTmp));
                } else {
                    $var = $this->parseWritableIdentifier($item->value);
                    if ($this->isVarExpr($item->value) and !$this->hasVar($var)) {
                        $this->addLocalVar($var, self::TYPE_VAR);
                    }
                    $code .= "{$var} = {$tmpVar}.item({$key}); ";
                }
            } else {
                abort($item);
            }
        }
        $this->indentLevel--;

        return $code . '}';
    }

    protected function parseAssignFinally(Expr $left, Expr $right): string
    {
        $this->assertNotNullsafeWriteContext($left);
        if ($left instanceof Expr\List_) {
            return $this->parseAssignToList($left, $right);
        }

        $propertyWriteTarget = $this->preparePropertyWriteTarget($left);
        $type = $this->detectTypeOfExpr($right);
        $finalVarType = $this->getNormalAssignType($type);
        $runtimeObjectAssignClass = '';
        $rightExprOverride = null;
        if ($type === self::TYPE_VOID) {
            $type = self::TYPE_VAR;
        }

        if ($propertyWriteTarget !== null && $this->shouldUseDynamicNativePropertyWrite($left, $type)) {
            return $this->parseAssignPropertyFetch($left, $right, $propertyWriteTarget);
        }

        if ($this->isVarExpr($left)) {
            $var = $this->parseWritableIdentifier($left);
            if ($var === 'this_') {
                $this->fatalError($left, 'Cannot re-assign $this');
            }
            if ($this->isStdContainer($var)) {
                $copyAssign = $this->parseStdContainerCopyAssign($var, $right);
                if ($copyAssign !== null) {
                    return $copyAssign;
                }
            }
            // 类型推断，获取对象的类名，如果不是对象则返回空字符串
            $rightClass = $this->detectClassOfExpr($right);
            // 右值是一个对象，已获得类的名称，左值必须与右值的类一致
            if ($rightClass) {
                if (!$this->hasVar($var)) {
                    $this->addLocalVar($var, self::TYPE_OBJECT);
                    $this->addObject($var, $rightClass);
                } elseif (($leftClass = $this->getDeclaredObjectType($var)) !== '') {
                    if ($this->isObjectClassStaticallyAssignableTo($rightClass, $leftClass)) {
                        // A child object can be assigned to a parent typed object.
                    } elseif ($this->isInterface($rightClass) || $this->isAbstractClass($rightClass) || $this->isObjectClassStaticallyAssignableTo($leftClass, $rightClass)) {
                        if ($this->isKnownConcreteObjectExpr($right, $rightClass)) {
                            $this->fatalError($left, "Cannot re-assign typed object `\${$var}` from `{$leftClass}` to `{$rightClass}`");
                        }
                        // Parent/interface/abstract declarations are not precise enough for a concrete typed object.
                        $runtimeObjectAssignClass = $leftClass;
                    } else {
                        $this->fatalError($left, "Cannot re-assign typed object `\${$var}` from `{$leftClass}` to `{$rightClass}`");
                    }
                } else {
                    $this->checkVarAssignExpr($left, $this->getVarType($var), self::TYPE_OBJECT);
                }
            } else {
                if ($this->isMethodCall($right) and $this->isNamedMethod($right->name)) {
                    $methodName = $right->name->toString();
                    if (in_array($methodName, ['toStdArray', 'toStdVector', 'toStdMap', 'toStdOrderedMap'], true)) {
                        if ($this->hasVar($var)) {
                            $this->fatalError($left, "Cannot re-assign `\${$var}` to {$methodName}()");
                        }
                        if ($this->context->scopeLevel > 1) {
                            $this->fatalError($left, "Must use {$methodName}() in the top-level scope of the function");
                        }
                        return $this->parseToStdAssign($var, $right);
                    }
                }
                if ($this->isFuncCallExpr($right) and $this->isNameExpr($right->name)) {
                    $fn = $this->parseIdentifier($right->name);
                    if (count($right->args) === 1 and $fn === 'any') {
                        $type = self::TYPE_VAR;
                        if (!$this->hasVar($var)) {
                            $this->addLocalVar($var, $type);
                            $finalVarType = $type;
                            return $var . ' = ' . $this->parseIdentifier($right->args[0]->value);
                        }
                        $rightExprOverride = $this->parseIdentifier($right->args[0]->value);
                    } else {
                        $type = $type === self::TYPE_VOID ? self::TYPE_VAR : $type;
                    }
                } elseif ($this->isStaticCall($right) and $this->isNameExpr($right->class) and $this->isIdExpr($right->name)) {
                    $class = $this->parseIdentifier($right->class);
                    if ($class === 'std') {
                        if (in_array($right->name->toString(), ['array', 'vector', 'map', 'ordered_map'], true)) {
                            if ($this->hasVar($var)) {
                                $this->fatalError($left, "Cannot re-assign `\${$var}` to std::{$right->name->toString()}");
                            }
                            if ($this->context->scopeLevel > 1) {
                                $this->fatalError($left, "Must create std::{$right->name->toString()} in the top-level scope of the function");
                            }
                            if ($right->name->toString() === 'array') {
                                $this->addLocalVar($var, self::TYPE_STD_ARRAY);
                                return $this->parseStdArray($var, $right);
                            }
                            if ($right->name->toString() === 'vector') {
                                $this->addLocalVar($var, self::TYPE_STD_VECTOR);
                                return $this->parseStdVector($var, $right);
                            }
                            if ($right->name->toString() === 'map') {
                                $this->addLocalVar($var, self::TYPE_STD_MAP);
                                return $this->parseStdMap($var, $right);
                            }
                            $this->addLocalVar($var, self::TYPE_STD_ORDERED_MAP);
                            return $this->parseStdOrderedMap($var, $right);
                        } else {
                            $valueExpr = $this->parseStdCall($right);
                            if (!$this->hasVar($var)) {
                                $finalVarType = $right->getAttribute('nativeType');
                                $this->addLocalVar($var, $finalVarType);
                            }
                            return $var . ' = ' . $valueExpr;
                        }
                    }
                } elseif ($this->isVarExpr($right)) {
                    $rightVar = $this->parseIdentifier($right);
                    $type = $this->isStdContainer($rightVar) ? self::TYPE_ARRAY : $this->getVarType($rightVar);
                    $finalVarType = $this->getNormalAssignType($type);
                    $leftClass = $this->getDeclaredObjectType($var);
                    $rightClass = $this->getDeclaredObjectType($rightVar);
                    if ($leftClass !== '' and $rightClass !== '') {
                        if ($this->isObjectClassStaticallyAssignableTo($rightClass, $leftClass)) {
                            // A child object can be assigned to a parent typed object.
                        } elseif ($this->isInterface($rightClass) || $this->isAbstractClass($rightClass) || $this->isObjectClassStaticallyAssignableTo($leftClass, $rightClass)) {
                            $runtimeObjectAssignClass = $leftClass;
                        } else {
                            $this->fatalError($left, "Cannot re-assign typed object `\${$var}` from `{$leftClass}` to `{$rightClass}`");
                        }
                    }
                }
                // 变量第一次被赋值，确定其类型，由于 PHP 的变量作用域是 function 级的，在 for/while 块中声明的变量，可以在块外使用
                if (!$this->hasVar($var)) {
                    $finalVarType = $this->getNormalAssignType($type);
                    $finalVarType = $this->isNativeType($finalVarType) ? $this->getNativeType($finalVarType) : $finalVarType;
                    $this->addLocalVar($var, $finalVarType);
                } else {
                    $finalVarType = $this->getVarType($var);
                    $this->checkVarAssignExpr($left, $finalVarType, $type);
                    $declaredObjectClass = $this->getDeclaredObjectType($var);
                    if ($finalVarType === self::TYPE_OBJECT && $declaredObjectClass !== '' && ($type === self::TYPE_VAR || $type === self::TYPE_OBJECT)) {
                        $runtimeObjectAssignClass = $declaredObjectClass;
                    }
                }
            }
        } elseif ($this->isPropertyFetch($left) and !$this->isNativePropertyAccess($left)) {
            return $this->parseAssignPropertyFetch($left, $right, $propertyWriteTarget);
        } elseif ($this->isArrayDimFetch($left) and $this->isVarExpr($left->var)) {
            $tmp = $this->parseIdentifier($left->var);
            if ($this->getVarType($tmp) === self::TYPE_STR and $left->dim === null) {
                $this->fatalError($left, 'Cannot use [] for strings');
            }
            if ($this->isStdContainerExpr($left)) {
                return $this->parseStdContainerAssign($left, $right);
            }
            return $this->parseAssignArrayDim($left, $right);
        } elseif ($this->isArrayDimFetch($left) and $this->isPropertyFetch($left->var)) {
            return $this->parseAssignPropertyArrayDim($left, $right);
        }

        if ($propertyWriteTarget !== null) {
            $this->assertCanAssignPropertyWrite($propertyWriteTarget, $right);
        }

        $var = $this->parseWritableIdentifier($left);
        $rightExpr = $rightExprOverride ?? $this->parseAssignRightExpr($right);
        if ($propertyWriteTarget !== null) {
            $rightExpr = $this->wrapPropertyWriteTypeCheck($propertyWriteTarget, $right, $rightExpr);
        }
        if ($runtimeObjectAssignClass !== '') {
            $rightExpr = 'php::toObject(' . $rightExpr . ', ' . $this->getClassEntryPtr($runtimeObjectAssignClass) . ')';
        }
        $leftExprType = $this->detectTypeOfExpr($left);
        $rightExprType = $this->detectTypeOfExpr($right);
        if ($propertyWriteTarget !== null && ($propertyDef = $this->getNativePropertyDef($left)) !== null) {
            return $var . ' = ' . $this->convertExprFromType($propertyDef->type, $rightExpr);
        }
        if ($finalVarType === self::TYPE_VAR) {
            return $var . ' = ' . $rightExpr;
        } else {
            return $var . ' = ' . $this->convertExprType($rightExpr, $leftExprType, $rightExprType);
        }
    }

    protected function shouldUseDynamicNativePropertyWrite(Expr $left, string $rightType): bool
    {
        if (!$this->isPropertyFetch($left)) {
            return false;
        }

        $def = $this->getNativePropertyDef($left);
        if ($def === null) {
            return false;
        }

        if ($rightType === self::TYPE_VAR) {
            return true;
        }

        return in_array($def->type, [self::TYPE_INT, self::TYPE_FLOAT, self::TYPE_BOOL, self::TYPE_STR], true)
            && $rightType !== $def->type;
    }

    protected function parseStdContainerCopyAssign(string $leftVar, Expr $right): ?string
    {
        $rightInfo = $this->getStdContainerExprInfo($right);
        if ($rightInfo === null) {
            return null;
        }

        $leftInfo = $this->getStdContainerVarInfo($leftVar);
        if (!$this->isSameStdContainerInfo($leftInfo, $rightInfo)) {
            $this->fatalError($right, 'Cannot copy std container with different type');
        }

        return $leftVar . '_ref = ' . $this->parseStdContainerCopyExpr($right);
    }

    protected function parseAssignRightExpr(Expr $right): string
    {
        $rightExpr = $this->parseExprAsValue($right);
        if ($this->isVarExpr($right)) {
            $rightVar = $this->parseIdentifier($right);
            if ($this->isStdContainer($rightVar)) {
                return $this->convertArrayExpr($rightExpr);
            }
        }
        return $rightExpr;
    }

    protected function removeAssignOp(string $op): string
    {
        return str_replace('=', '', $op);
    }

    protected function parseAssignOp(Expr\AssignOp $node, string $op): string
    {
        $this->assertNotNullsafeWriteContext($node->var);
        $propertyWriteTarget = $this->preparePropertyWriteTarget($node->var);
        $this->guardLiteralDivisionByZero($node->expr, $op);

        $nativePropertyAssignOp = $this->parseNativePropertyAssignOp($node, $op);
        if ($nativePropertyAssignOp !== null) {
            return $nativePropertyAssignOp;
        }

        $var          = $this->parseWritableIdentifier($node->var);
        $expr         = $this->parseIdentifier($node->expr);

        if ($this->isVarExpr($node->var)) {
            if (!$this->hasVar($var)) {
                $this->fatalError($node->var, 'Cannot assign to undefined variable');
            }
            $type         = $this->detectVarType($node->var);
            $rightType    = $this->detectTypeOfExpr($node->expr);

            // Big* types: expand compound assignment to static method call.
            // BigInt/BigDecimal/BigFloat are immutable Box types stored inside
            // php::Var — Variant::operator+= calls ZendVM add_function which
            // cannot handle them.  We must generate `$v = Type::add($v, $x)`.
            if ($type === self::TYPE_BIGINT || $type === self::TYPE_DECIMAL || $type === self::TYPE_BIGFLOAT) {
                return $this->parseBigAssignOp($node, $var, $type, $expr, $rightType, $op);
            }

            $rightExprStr = $this->convertExprType($expr, $type, $rightType);
            if ($this->isAssignOpConcat($op)) {
                if ($this->isArrayVar($node->var)) {
                    $this->fatalError($node->var, 'Cannot concat string to array');
                }
                return $var . ' = php::concat(' . $var . ', ' . $rightExprStr . ')';
            }
            if ($this->isAssignOpPow($op)) {
                $powExpr = 'php::fn::pow(' . $var . ', ' . $rightExprStr . ')';
                return $var . ' = ' . $this->convertVarType($var, $powExpr);
            }
            return $var . ' ' . $op . ' ' . $rightExprStr;
        }

        if ($this->isArrayDimFetch($node->var)) {
            if ($this->isStdContainerExpr($node->var)) {
                return $this->parseStdContainerAssignOp($node, $op);
            }
            /**
             * $count[$r] -= 1;
             * 需要转为下面语句：
             * $tmp_var = $count[$r] - 1;
             * $count[$r] = $tmp_var;.
             */
            $type      = $this->detectVarType($node->var);
            $rightType = $this->detectTypeOfExpr($node->expr);
            $tmpVar    = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, $rightType);
            $dim      = $this->parseIdentifier($node->var->dim);
            $readVar  = $this->parseArrayDimFetchRead($node->var);
            $binaryOp = $this->removeAssignOp($op);

            if ($binaryOp === '.') {
                $this->context->beforeStmtLines[] = "{$tmpVar} = php::concat(" .
                    $this->convertVarType($tmpVar, $readVar) . ', ' .
                    $this->convertExprType($expr, $type, $rightType) . ');';
            } elseif ($type === self::TYPE_BIGINT || $type === self::TYPE_DECIMAL || $type === self::TYPE_BIGFLOAT) {
                $bigAssign = $this->parseBigAssignOpExpr($readVar, $type, $expr, $rightType, $binaryOp, $node->var, $node->expr);
                $this->context->beforeStmtLines[] = "{$tmpVar} = {$bigAssign};";
            } else {
                $this->context->beforeStmtLines[] = "{$tmpVar} = " .
                    $this->convertVarType($tmpVar, $readVar) . ' ' .
                    $binaryOp . ' ' .
                    $this->convertExprType($expr, $type, $rightType) . ';';
            }

            if ($this->isVarExpr($node->var->var) && $node->var->var->name === 'GLOBALS') {
                return $var . ' = ' . $tmpVar;
            }
            return '(' . $this->parseArrayDimStore($node->var->var, $dim, $tmpVar) . ', ' . $tmpVar . ')';
        }

        if ($this->isPropertyFetch($node->var) and !$this->isNativePropertyAccess($node->var)) {
            if ($propertyWriteTarget !== null) {
                $this->assertCanAssignPropertyWrite($propertyWriteTarget, $node->expr);
            }
            $binaryOp = $this->removeAssignOp($op);
            $tmpVar = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, self::TYPE_VAR);
            $readProperty = $this->emitDynamicPropertyFetchRead($node->var, $propertyWriteTarget);
            if ($this->isAssignOpConcat($op)) {
                $this->context->beforeStmtLines[] = "{$tmpVar} = php::concat({$readProperty}, {$expr});";
            } elseif ($this->isAssignOpPow($op)) {
                $this->context->beforeStmtLines[] = "{$tmpVar} = php::fn::pow({$readProperty}, {$expr});";
            } else {
                $this->context->beforeStmtLines[] = "{$tmpVar} = {$readProperty} {$binaryOp} ({$expr});";
            }
            $this->context->afterStmtLines[] = $this->emitDynamicPropertyFetchWrite($node->var, $tmpVar, $propertyWriteTarget) . ';';
            return $tmpVar;
        }

        if ($this->isAssignOpConcat($op)) {
            return $var . '.append(' . $expr . ')';
        }
        return $var . ' ' . $op . ' (' . $expr . ')';
    }

    protected function parseNativePropertyAssignOp(Expr\AssignOp $node, string $op): ?string
    {
        if (!$this->isPropertyFetch($node->var)) {
            return null;
        }

        $def = $this->getNativePropertyDef($node->var);
        if ($def === null) {
            return null;
        }

        $rightType = $this->detectTypeOfExpr($node->expr);
        if (!$this->canUseNativePropertyAssignOp($def->type, $rightType, $op)) {
            return null;
        }

        $var = $this->parseWritableIdentifier($node->var);
        if (!$this->isNativePropertyTypedValue($node->var)) {
            $helper = $def->type === self::TYPE_FLOAT ? 'php_aot_static_float_ref' : 'php_aot_static_int_ref';
            $var = $helper . '(' . $var . '.unwrap_ptr())';
        }

        return $var . ' ' . $op . ' (' . $this->convertExprFromType($def->type, $this->parseIdentifier($node->expr)) . ')';
    }

    protected function canUseNativePropertyAssignOp(string $propertyType, string $rightType, string $op): bool
    {
        if ($propertyType !== $rightType) {
            return false;
        }

        return match ($propertyType) {
            self::TYPE_INT => in_array($op, ['+=', '-=', '*=', '%=', '<<=', '>>=', '&=', '|=', '^='], true),
            self::TYPE_FLOAT => in_array($op, ['+=', '-=', '*=', '/='], true),
            default => false,
        };
    }

    protected function parseBigAssignOp(Expr\AssignOp $node, string $var, string $type, string $expr, string $rightType, string $op): string
    {
        $binaryOp = $this->removeAssignOp($op);
        $bigExpr  = $this->parseBigAssignOpExpr($var, $type, $expr, $rightType, $binaryOp, $node->var, $node->expr);
        return $var . ' = ' . $bigExpr;
    }

    protected function parseBigAssignOpExpr(string $leftExpr, string $leftType, string $rightExpr, string $rightType, string $binaryOp, NodeAbstract $errorNode, ?NodeAbstract $rightNode = null): string
    {
        [$class, $opMap] = match ($leftType) {
            self::TYPE_BIGINT   => ['BigInt',   ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div', '%' => 'mod', '&' => 'bitAnd', '|' => 'bitOr', '^' => 'bitXor', '<<' => 'bitShiftLeft', '>>' => 'bitShiftRight']],
            self::TYPE_DECIMAL  => ['Decimal',  ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div', '%' => 'mod']],
            self::TYPE_BIGFLOAT => ['BigFloat', ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div']],
        };

        $method = $opMap[$binaryOp] ?? null;
        if ($method === null) {
            $this->fatalError($errorNode, "Unsupported compound assignment operator '{$binaryOp}' for type {$leftType}");
        }

        // For bitwise shifts, the right operand is a shift amount (Int), not BigInt
        $isShift = ($binaryOp === '<<' || $binaryOp === '>>');
        $convertedRight = match ($leftType) {
            self::TYPE_BIGINT   => $isShift ? $rightExpr : $this->convertBigIntExpr($rightExpr, $rightType),
            self::TYPE_DECIMAL  => $this->convertDecimalExpr($rightExpr, $rightType, $rightNode),
            self::TYPE_BIGFLOAT => $this->convertBigFloatExpr($rightExpr, $rightType),
        };

        return 'php::' . $class . '::' . $method . '(' . $leftExpr . ', ' . $convertedRight . ')';
    }

    protected function parseAssignOpConcat(Expr\AssignOp\Concat $expr): string
    {
        return $this->parseAssignOp($expr, '.=');
    }

    protected function parseAssignOpPlus(Expr\AssignOp\Plus $expr): string
    {
        return $this->parseAssignOp($expr, '+=');
    }

    protected function parseAssignOpMinus(Expr\AssignOp\Minus $expr): string
    {
        return $this->parseAssignOp($expr, '-=');
    }

    protected function parseAssignOpMod(Expr\AssignOp\Mod $expr): string
    {
        return $this->parseAssignOp($expr, '%=');
    }

    protected function parseAssignOpMul(Expr\AssignOp\Mul $expr): string
    {
        return $this->parseAssignOp($expr, '*=');
    }

    protected function parseAssignOpDiv(Expr\AssignOp\Div $expr): string
    {
        return $this->parseAssignOp($expr, '/=');
    }

    protected function parseAssignOpBitwiseAnd(Expr\AssignOp\BitwiseAnd $expr): string
    {
        return $this->parseAssignOp($expr, '&=');
    }

    protected function parseAssignOpBitwiseOr(Expr\AssignOp\BitwiseOr $expr): string
    {
        return $this->parseAssignOp($expr, '|=');
    }

    protected function parseAssignOpPow(Expr\AssignOp\Pow $expr): string
    {
        return $this->parseAssignOp($expr, '**=');
    }

    protected function parseArrayDimStore($array, $dim, $var): string
    {
        $id = $this->parseWritableIdentifier($array);

        return $id . '.offsetSet(' . $dim . ', ' . $var . ')';
    }

    protected function parseAssignOpShiftLeft(Expr\AssignOp\ShiftLeft $node): string
    {
        return $this->parseAssignOp($node, '<<=');
    }

    protected function parseAssignOpShiftRight(Expr\AssignOp\ShiftRight $node): string
    {
        return $this->parseAssignOp($node, '>>=');
    }

    protected function parseAssignOpBitwiseXor(Expr\AssignOp\BitwiseXor $node): string
    {
        return $this->parseAssignOp($node, '^=');
    }

    protected function parseAssignRef(Expr\AssignRef $expr): string
    {
        $this->assertNotNullsafeWriteContext($expr->var);
        if ($expr->expr instanceof Expr\NullsafePropertyFetch) {
            $this->fatalError($expr->expr, 'Cannot take reference of a nullsafe chain');
        }

        $left = $this->parseWritableIdentifier($expr->var);

        if ($this->isVarExpr($expr->var)) {
            if (!$this->hasVar($left)) {
                $this->addLocalVar($left, self::TYPE_REF);
            } else {
                $type = $this->getVarType($left);
                if ($type !== self::TYPE_REF) {
                    $this->fatalError($expr, 'Cannot assign reference to variable of type ' . $type);
                }
            }
        }

        $tmpVar = $this->addTmpVar(self::TYPE_REF);
        $rightExpr = '';

        if ($this->isVarExpr($expr->expr)) {
            $rightExpr = $tmpVar . ' = ' . $this->parseIdentifier($expr->expr) . '.toReference()';
        } elseif ($this->isPropertyFetch($expr->expr)) {
            $left = $this->parseIdentifier($expr->var);
            $rightExpr = $tmpVar . ' = ' . $this->emitDynamicPropertyFetchRef($expr->expr, $expr);
        } elseif ($this->isArrayDimFetch($expr->expr)) {
            $left = $this->parseIdentifier($expr->var);
            $array = $this->parseWritableIdentifier($expr->expr->var);
            if ($expr->expr->dim == null) {
                $this->fatalError($expr, 'Cannot assign reference to array dim fetch without dim');
            }
            $rightExpr = $tmpVar . ' = ' . $array . '.itemRef(' . $this->parseIdentifier($expr->expr->dim) . ')';
        } else {
            $this->fatalError($expr, 'Cannot assign reference to ' . $this->parseIdentifier($expr->expr));
        }

        $this->context->beforeStmtLines[] = $rightExpr . ';';
        return $left . ' = &' . $tmpVar;
    }

    protected function parseAssignPropertyArrayDim(NodeAbstract $left, NodeAbstract $right): string
    {
        $propertyWriteTarget = $this->preparePropertyWriteTarget($left->var);
        $code     = '';
        $value    = $this->parseExprAsValue($right);

        $tmp = $this->genTmpVarName();
        $this->addLocalVar($tmp, self::TYPE_VAR);

        if ($left->dim === null) {
            return $code . '((' . $tmp . ' = ' . $value . ', ' . $this->emitDynamicPropertyFetchAppendArray($left->var, $tmp, $propertyWriteTarget) . '), ' . $tmp . ')';
        }
        $dim = $this->parseIdentifier($left->dim);

        return $code . '((' . $tmp . ' = ' . $value . ', ' . $this->emitDynamicPropertyFetchUpdateArray($left->var, $dim, $tmp, $propertyWriteTarget) . '), ' . $tmp . ')';
    }

    protected function parseAssignOpCoalesce(Expr\AssignOp\Coalesce $expr): string
    {
        $this->checkLeftValue($expr->var);
        $isset = $this->parseChainedExpr($expr->var, self::OP_ISSET);

        $var = $this->parseWritableIdentifier($expr->var);
        $propertyWriteTarget = $this->preparePropertyWriteTarget($expr->var);

        if ($propertyWriteTarget !== null) {
            $this->assertCanAssignPropertyWrite($propertyWriteTarget, $expr->expr);
        }

        $right = $this->parseExpr($expr->expr);
        if ($propertyWriteTarget !== null) {
            $right = $this->wrapPropertyWriteTypeCheck($propertyWriteTarget, $expr->expr, $right);
        }
        if ($this->isVarExpr($expr->expr) and !$this->hasVar($right)) {
            $this->errorUndefinedVariable($expr->expr);
        }
        if ($this->isVarExpr($expr->var) and !$this->hasVar($var)) {
            $this->addLocalVar($var, $this->getNormalAssignType($this->detectTypeOfExpr($expr->expr)));
        }
        return '(' . $isset . '?' . $var . ':(' . $var . ' = ' . $right . '))';
    }

    protected function getNormalAssignType(string $type): string
    {
        return $type === self::TYPE_REF || $type === self::TYPE_VOID ? self::TYPE_VAR : $type;
    }

}
