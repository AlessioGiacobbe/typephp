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
        $oriInAssignExpr    = $this->context->inAssignExpr;
        $this->context->inAssignExpr = true;
        $array              = $this->parseIdentifier($left->var);
        $this->context->inAssignExpr = $oriInAssignExpr;
        $code               = '';
        if (!$this->hasVar($array) and $this->isVarExpr($left->var)) {
            $this->addLocalVar($array, self::TYPE_ARRAY);
        }

        $value = $this->trimBrackets($this->parseExpr($right));

        $tmp = $this->genTmpVarName();
        $this->addLocalVar($tmp, self::TYPE_VAR);

        if ($left->dim === null) {
            return $code . '((' . $tmp . ' = ' . $value . ', ' . "{$array}.offsetSet(" . self::VALUE_NULL . ", {$tmp})" . '), ' . $tmp . ')';
        }
        $dim = $this->trimBrackets($this->parseIdentifier($left->dim));

        return $code . '((' . $tmp . ' = ' . $value . ', ' . "{$array}.offsetSet({$dim}, {$tmp})" . '), ' . $tmp . ')';
    }

    protected function parseAssignPropertyFetch(NodeAbstract $left, NodeAbstract $right, ?PropertyWriteTarget $target = null): string
    {
        if ($target !== null) {
            $this->assertCanAssignPropertyWrite($target, $right);
        }

        $rightExpr = $this->trimBrackets($this->parseExpr($right));
        if ($target !== null) {
            $rightExpr = $this->wrapPropertyWriteTypeCheck($target, $right, $rightExpr);
        } else {
            $rightExpr = $this->wrapObjectPropertyAssignTypeCheck($left, $right, $rightExpr);
        }

        $tmp = $this->genTmpVarName();
        $this->addLocalVar($tmp, self::TYPE_VAR);
        // Comma expression: store RHS → execute side effect → evaluate to stored value
        if ($target !== null && $target->isDynamicObjectProperty()) {
            return '((' . $tmp . ' = ' . $rightExpr . ', ' . $this->emitDynamicPropertyTargetWrite($target, $tmp) . '), ' . $tmp . ')';
        }

        $array = $this->parseIdentifier($left->var);
        $propName = $this->identifierToStr($left->name, literal: true);
        return '((' . $tmp . ' = ' . $rightExpr . ', ' . $this->emitDynamicPropertyWrite($array, $propName, $tmp) . '), ' . $tmp . ')';
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
                if ($item->value instanceof Expr\List_) {
                    $nestedTmp = $this->genTmpVarName();
                    $this->addLocalVar($nestedTmp, self::TYPE_ARRAY);
                    $code .= "{$nestedTmp} = {$tmpVar}.item({$k}); ";
                    $code .= $this->parseAssignToList($item->value, new Variable($nestedTmp));
                } else {
                    $oriInAssignExpr = $this->context->inAssignExpr;
                    $this->context->inAssignExpr = true;
                    $var = $this->parseIdentifier($item->value);
                    $this->context->inAssignExpr = $oriInAssignExpr;
                    if ($this->isVarExpr($item->value) and !$this->hasVar($var)) {
                        $this->addLocalVar($var, self::TYPE_VAR);
                    }
                    $code .= "{$var} = {$tmpVar}.item({$k}); ";
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

        $oriInAssignExpr = $this->context->inAssignExpr;
        $this->context->inAssignExpr = true;
        $var = $this->parseIdentifier($left);
        $this->context->inAssignExpr = $oriInAssignExpr;
        $propertyWriteTarget = $this->preparePropertyWriteTarget($left);
        if ($var === 'this_') {
            $this->fatalError($left, 'Cannot re-assign $this');
        }
        $finalVarType = $type = $this->detectTypeOfExpr($right);
        if ($type === self::TYPE_VOID) {
            $this->fatalError($right, 'Cannot use void expression as assignment value');
        }

        if ($this->isVarExpr($left)) {
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
                } elseif ($this->isTypedObject($var)) {
                    $leftClass = $this->getObjectType($var);
                    // 对象的类不一致，不能互相赋值，必须使用 toObject() 对齐类型
                    // 注意这里必须使用绝对相等比较，即使存在继承关系，类的方法也可能不一致
                    if ($leftClass !== $rightClass) {
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
                        }
                        return $var . ' = ' . $this->parseIdentifier($right->args[0]->value);
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
                    if ($this->isTypedObject($rightVar) and $this->isTypedObject($var)) {
                        $leftClass = $this->getObjectType($var);
                        $rightClass = $this->getObjectType($rightVar);
                        $this->fatalError($left, "Cannot re-assign typed object `\${$var}` from `{$leftClass}` to `{$rightClass}`");
                    }
                }
                // 变量第一次被赋值，确定其类型，由于 PHP 的变量作用域是 function 级的，在 for/while 块中声明的变量，可以在块外使用
                if (!$this->hasVar($var)) {
                    $finalVarType = $this->isNativeType($type) ? $this->getNativeType($type) : $type;
                    $this->addLocalVar($var, $finalVarType);
                } else {
                    $finalVarType = $this->getVarType($var);
                    $this->checkVarAssignExpr($left, $finalVarType, $type);
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

        $rightExpr = $this->parseAssignRightExpr($right);
        if ($propertyWriteTarget !== null) {
            $rightExpr = $this->wrapPropertyWriteTypeCheck($propertyWriteTarget, $right, $rightExpr);
        }
        $leftExprType = $this->detectTypeOfExpr($left);
        $rightExprType = $this->detectTypeOfExpr($right);
        if ($finalVarType === self::TYPE_VAR) {
            return $var . ' = ' . $rightExpr;
        } else {
            return $var . ' = ' . $this->convertExprType($rightExpr, $leftExprType, $rightExprType);
        }
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
        $rightExpr = $this->parseExpr($right);
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
        $oriInAssignExpr = $this->context->inAssignExpr;
        $this->context->inAssignExpr = true;
        $var          = $this->parseIdentifier($node->var);
        $this->context->inAssignExpr = $oriInAssignExpr;
        $expr         = $this->parseIdentifier($node->expr);
        $propertyWriteTarget = $this->preparePropertyWriteTarget($node->var);
        $this->guardLiteralDivisionByZero($node->expr, $op);

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
                return $var . '.append(' . $rightExprStr . ')';
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
            $binaryOp = $this->removeAssignOp($op);

            if ($binaryOp === '.') {
                $this->context->beforeStmtLines[] = "{$tmpVar} = php::concat(" .
                    $this->convertVarType($tmpVar, $var) . ', ' .
                    $this->convertExprType($expr, $type, $rightType) . ');';
            } elseif ($type === self::TYPE_BIGINT || $type === self::TYPE_DECIMAL || $type === self::TYPE_BIGFLOAT) {
                $bigAssign = $this->parseBigAssignOpExpr($var, $type, $expr, $rightType, $binaryOp, $node->var, $node->expr);
                $this->context->beforeStmtLines[] = "{$tmpVar} = {$bigAssign};";
            } else {
                $this->context->beforeStmtLines[] = "{$tmpVar} = " .
                    $this->convertVarType($tmpVar, $var) . ' ' .
                    $binaryOp . ' ' .
                    $this->convertExprType($expr, $type, $rightType) . ';';
            }

            return $this->parseArrayDimStore($node->var->var, $dim, $tmpVar);
        }

        if ($this->isPropertyFetch($node->var) and !$this->isNativePropertyAccess($node->var)) {
            if ($propertyWriteTarget !== null) {
                $this->assertCanAssignPropertyWrite($propertyWriteTarget, $node->expr);
            }
            $binaryOp = $this->removeAssignOp($op);
            $tmpVar = $this->genTmpVarName();
            $this->addLocalVar($tmpVar, self::TYPE_VAR);
            if ($propertyWriteTarget !== null && $propertyWriteTarget->isDynamicObjectProperty()) {
                $readProperty = $this->emitDynamicPropertyTargetRead($propertyWriteTarget);
            } else {
                $obj = $this->parseIdentifier($node->var->var);
                $propName = $this->identifierToStr($node->var->name, literal: true);
                $readProperty = $this->emitDynamicPropertyRead($obj, $propName);
            }
            if ($this->isAssignOpConcat($op)) {
                $this->context->beforeStmtLines[] = "{$tmpVar} = php::concat({$readProperty}, {$expr});";
            } elseif ($this->isAssignOpPow($op)) {
                $this->context->beforeStmtLines[] = "{$tmpVar} = php::fn::pow({$readProperty}, {$expr});";
            } else {
                $this->context->beforeStmtLines[] = "{$tmpVar} = {$readProperty} {$binaryOp} ({$expr});";
            }
            if ($propertyWriteTarget !== null && $propertyWriteTarget->isDynamicObjectProperty()) {
                $this->context->afterStmtLines[] = $this->emitDynamicPropertyTargetWrite($propertyWriteTarget, $tmpVar) . ';';
            } else {
                $this->context->afterStmtLines[] = $this->emitDynamicPropertyWrite($obj, $propName, $tmpVar) . ';';
            }
            return $tmpVar;
        }

        if ($this->isAssignOpConcat($op)) {
            return $var . '.append(' . $expr . ')';
        }
        return $var . ' ' . $op . ' (' . $expr . ')';
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
        $oriInAssignExpr = $this->context->inAssignExpr;
        $this->context->inAssignExpr = true;
        $id = $this->parseIdentifier($array);
        $this->context->inAssignExpr = $oriInAssignExpr;

        return $id . '.offsetSet(' . $this->trimBrackets($dim) . ', ' . $this->trimBrackets($var) . ')';
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

        $this->context->inAssignExpr = true;
        $left = $this->parseIdentifier($expr->var);
        $this->context->inAssignExpr = false;

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
            $propertyWriteTarget = $this->preparePropertyWriteTarget($expr->expr);
            if ($propertyWriteTarget !== null && $propertyWriteTarget->isDynamicObjectProperty()) {
                $rightExpr = $tmpVar . ' = ' . $this->emitDynamicPropertyTargetRef($propertyWriteTarget);
            } else {
                $object = $this->parseExpr($expr->expr->var);
                $prop = $this->identifierToStr($expr->expr->name);
                $rightExpr = $tmpVar . ' = ' . $object . '.attrRef(' . $prop . ')';
            }
        } elseif ($this->isArrayDimFetch($expr->expr)) {
            $left = $this->parseIdentifier($expr->var);
            $array = $this->parseIdentifier($expr->expr->var);
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
        if ($propertyWriteTarget !== null && $propertyWriteTarget->isDynamicObjectProperty()) {
            $obj = $propertyWriteTarget->objectExpr;
            $propName = $propertyWriteTarget->propertyExpr;
        } else {
            $obj = $this->parseIdentifier($left->var->var);
            $propName = $this->identifierToStr($left->var->name);
        }
        $code     = '';
        $value    = $this->trimBrackets($this->parseExpr($right));

        $tmp = $this->genTmpVarName();
        $this->addLocalVar($tmp, self::TYPE_VAR);

        if ($left->dim === null) {
            return $code . '((' . $tmp . ' = ' . $value . ', ' . "{$obj}.appendArrayProperty({$propName}, {$tmp})" . '), ' . $tmp . ')';
        }
        $dim = $this->trimBrackets($this->parseIdentifier($left->dim));

        return $code . '((' . $tmp . ' = ' . $value . ', ' . "{$obj}.updateArrayProperty({$propName}, {$dim}, {$tmp})" . '), ' . $tmp . ')';
    }

    protected function parseAssignOpCoalesce(Expr\AssignOp\Coalesce $expr): string
    {
        $this->checkLeftValue($expr->var);
        $isset = $this->parseChainedExpr($expr->var, self::OP_ISSET);

        $inAssignExpr = $this->context->inAssignExpr;
        $this->context->inAssignExpr = true;
        $var = $this->parseIdentifier($expr->var);
        $this->context->inAssignExpr = $inAssignExpr;
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
            $this->addLocalVar($var, $this->detectTypeOfExpr($expr->expr));
        }
        return '(' . $isset . '?' . $var . ':(' . $var . ' = ' . $right . '))';
    }

}
