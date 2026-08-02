<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Parser;

use TypePhp\Type;

use TypePhp\Generator\Symbol;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\NodeAbstract;

trait BinaryOpTrait
{
    protected function parseBinaryOp(NodeAbstract $left, NodeAbstract $right, string $op): string
    {
        $this->assertExprCanBeUsedAsValue($left, 'binary operand');
        $this->assertExprCanBeUsedAsValue($right, 'binary operand');

        // 运算逻辑，优先转为数字
        $leftExpr  = $this->parseOrderedBinaryOperand($left);
        $rightExpr = $this->parseOrderedBinaryOperand($right);

        $this->checkVarMustExist($left, $leftExpr);
        $this->checkVarMustExist($right, $rightExpr);

        $leftType  = $this->detectTypeOfExpr($left);
        $rightType = $this->detectTypeOfExpr($right);

        if ($leftType === Type::BIGFLOAT || $rightType === Type::BIGFLOAT) {
            // BigFloat cannot implicitly mix with BigInt or Decimal — risk of precision loss
            if ($leftType === Type::BIGINT || $rightType === Type::BIGINT) {
                $this->fatalError($left, 'Cannot mix BigFloat and BigInt implicitly. Use std::bigFloat() to convert explicitly.');
            }
            if ($leftType === Type::DECIMAL || $rightType === Type::DECIMAL) {
                $this->fatalError($left, 'Cannot mix BigFloat and Decimal implicitly. Use std::bigFloat() to convert explicitly.');
            }
            if ($leftType !== Type::BIGFLOAT) {
                $leftExpr = $this->convertBigFloatExpr($leftExpr, $leftType);
            }
            if ($rightType !== Type::BIGFLOAT) {
                $rightExpr = $this->convertBigFloatExpr($rightExpr, $rightType);
            }
            $arithOpMap = ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div'];
            $method = $arithOpMap[$op] ?? null;
            if ($method) {
                return 'php::BigFloat::' . $method . '(' . $leftExpr . ', ' . $rightExpr . ')';
            }
            $cmpOpMap = ['<' => '< 0', '>' => '> 0', '<=' => '<= 0', '>=' => '>= 0'];
            if (isset($cmpOpMap[$op])) {
                return 'php::toBool(php::BigFloat::cmp(' . $leftExpr . ', ' . $rightExpr . ') ' . $cmpOpMap[$op] . ')';
            }
        }

        if ($leftType === Type::DECIMAL || $rightType === Type::DECIMAL) {
            // BigInt and Decimal cannot implicitly mix — risk of precision loss
            if ($leftType === Type::BIGINT || $rightType === Type::BIGINT) {
                $this->fatalError($left, 'Cannot mix BigInt and Decimal implicitly. Use std::decimal() or std::bigInt() to convert explicitly.');
            }
            if ($leftType !== Type::DECIMAL) {
                $leftExpr = $this->convertDecimalExpr($leftExpr, $leftType, $left);
            }
            if ($rightType !== Type::DECIMAL) {
                $rightExpr = $this->convertDecimalExpr($rightExpr, $rightType, $right);
            }
            $arithOpMap = ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div', '%' => 'mod'];
            $method = $arithOpMap[$op] ?? null;
            if ($method) {
                return 'php::Decimal::' . $method . '(' . $leftExpr . ', ' . $rightExpr . ')';
            }
            $cmpOpMap = ['<' => '< 0', '>' => '> 0', '<=' => '<= 0', '>=' => '>= 0'];
            if (isset($cmpOpMap[$op])) {
                return 'php::toBool(php::Decimal::cmp(' . $leftExpr . ', ' . $rightExpr . ') ' . $cmpOpMap[$op] . ')';
            }
        }

        if ($leftType === Type::BIGINT || $rightType === Type::BIGINT) {
            // Bitwise shifts: right operand is shift amount, must stay as Int
            if ($op === '<<' || $op === '>>') {
                if ($leftType !== Type::BIGINT) {
                    $leftExpr = $this->convertBigIntExpr($leftExpr, $leftType);
                }
                if ($rightType === Type::BIGINT) {
                    $rightExpr = 'php::BigInt::toInt(' . $rightExpr . ')';
                } elseif ($rightType !== Type::INT) {
                    $rightExpr = $this->convertExprType($rightExpr, $rightType, Type::INT);
                }
                $method = ($op === '<<') ? 'bitShiftLeft' : 'bitShiftRight';
                return 'php::BigInt::' . $method . '(' . $leftExpr . ', ' . $rightExpr . ')';
            }
            if ($leftType !== Type::BIGINT) {
                $leftExpr = $this->convertBigIntExpr($leftExpr, $leftType);
            }
            if ($rightType !== Type::BIGINT) {
                $rightExpr = $this->convertBigIntExpr($rightExpr, $rightType);
            }
            $arithOpMap = ['+' => 'add', '-' => 'sub', '*' => 'mul', '/' => 'div', '%' => 'mod', '&' => 'bitAnd', '|' => 'bitOr', '^' => 'bitXor'];
            $method = $arithOpMap[$op] ?? null;
            if ($method) {
                return 'php::BigInt::' . $method . '(' . $leftExpr . ', ' . $rightExpr . ')';
            }
            $cmpOpMap = ['<' => '< 0', '>' => '> 0', '<=' => '<= 0', '>=' => '>= 0'];
            if (isset($cmpOpMap[$op])) {
                return 'php::toBool(php::BigInt::cmp(' . $leftExpr . ', ' . $rightExpr . ') ' . $cmpOpMap[$op] . ')';
            }
        }

        // Any Big*-typed operand reaching here means no Big* block handled the operator
        $bigTypes = [Type::BIGFLOAT, Type::DECIMAL, Type::BIGINT];
        if (in_array($leftType, $bigTypes, true) || in_array($rightType, $bigTypes, true)) {
            $this->fatalError($left, "Operator '{$op}' is not supported for Big* numeric types");
        }

        // Only promote between native types (Int ↔ Float).  When one side is
        // php::Var, let the Variant operator handle type coercion so that
        // run-time PHP type-juggling rules are followed correctly.
        if ($leftType === Type::FLOAT && $rightType === Type::INT) {
            $rightExpr = $this->convertExprType($rightExpr, Type::FLOAT, $rightType);
        } elseif ($rightType === Type::FLOAT && $leftType === Type::INT) {
            $leftExpr = $this->convertExprType($leftExpr, $leftType, Type::FLOAT);
        }

        $this->guardLiteralDivisionByZero($right, $op);

        if ($op === '%' and !($leftType === Type::INT and $rightType === Type::INT)) {
            return 'php::fn::mod(' . $leftExpr . ', ' . $rightExpr . ')';
        }

        return '((' . $leftExpr . ') ' . $op . ' (' . $rightExpr . '))';
    }

    protected function shouldMaterializeOrderedOperand(NodeAbstract $expr): bool
    {
        if ($expr instanceof Expr\BinaryOp) {
            return $this->shouldMaterializeOrderedOperand($expr->left)
                || $this->shouldMaterializeOrderedOperand($expr->right);
        }

        return $expr instanceof Expr\FuncCall
            || $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\StaticCall
            || $expr instanceof Expr\New_
            || $expr instanceof Expr\Assign
            || $expr instanceof Expr\AssignRef
            || $expr instanceof Expr\AssignOp
            || $expr instanceof Expr\PostInc
            || $expr instanceof Expr\PostDec
            || $expr instanceof Expr\PreInc
            || $expr instanceof Expr\PreDec
            || $expr instanceof Expr\Print_
            || $expr instanceof Expr\Array_
            || $expr instanceof Expr\ArrayDimFetch
            || $expr instanceof Expr\PropertyFetch
            || $expr instanceof Expr\StaticPropertyFetch
            || $expr instanceof Expr\Ternary
            || $expr instanceof Expr\Match_
            || $expr instanceof Expr\NullsafeMethodCall
            || $expr instanceof Expr\NullsafePropertyFetch
            || $expr instanceof Expr\Clone_
            || $expr instanceof Expr\Include_
            || $expr instanceof Expr\Eval_;
    }

    protected function parseOrderedBinaryOperand(NodeAbstract $expr): float|int|string
    {
        return $this->parseOrderedOperand($expr, true);
    }

    protected function parseOrderedOperand(NodeAbstract $expr, bool $numeric): float|int|string
    {
        $this->assertExprCanBeUsedAsValue($expr, 'operand');
        if (!$this->shouldMaterializeOrderedOperand($expr)) {
            return $numeric ? $this->parseNumericIdentifier($expr) : $this->parseIdentifier($expr);
        }

        [$value, $beforeStmts, $afterStmts] = $this->parseExprWithCapturedStmts($expr);
        $this->appendCapturedStmtLinesToContext($beforeStmts);

        $type = $this->getOrderedOperandTmpType($expr, (string) $value);
        $tmpVar = $this->addTmpVar($type);
        $this->context->beforeStmtLines[] = $tmpVar . ' = ' . $value . ';';
        $this->appendCapturedStmtLinesToContext($afterStmts);
        return $tmpVar;
    }

    protected function getOrderedOperandTmpType(NodeAbstract $expr, string $value): string
    {
        if ($expr instanceof Expr\BinaryOp) {
            $type = $this->detectTypeOfExpr($expr);
            return in_array($type, [Type::BIGINT, Type::DECIMAL, Type::BIGFLOAT], true) ? $type : Type::VAR;
        }

        if (
            $expr instanceof Expr\FuncCall
            || $expr instanceof Expr\MethodCall
            || $expr instanceof Expr\StaticCall
        ) {
            $type = $this->detectTypeOfExpr($expr);
            return in_array($type, [Type::BIGINT, Type::DECIMAL, Type::BIGFLOAT], true) ? $type : Type::VAR;
        }

        if ($expr instanceof Expr\PropertyFetch) {
            $nativePropertyVar = $this->getNativePropertyVar($expr);
            if ($nativePropertyVar !== null && $nativePropertyVar === $value) {
                $info = $this->getObjectPropInfoByVar($nativePropertyVar);
                if ($info !== null) {
                    return $info['type'];
                }
                $def = $this->getNativePropertyDef($expr);
                if ($def && $this->isNativePropertyTypedValue($expr)) {
                    return $def->type;
                }
            }
            return Type::VAR;
        }

        if ($expr instanceof Expr\StaticPropertyFetch) {
            $def = $this->getNativePropertyDef($expr);
            if ($def && $this->isNativePropertyTypedValue($expr)) {
                return $def->type;
            }
            return Type::VAR;
        }

        if ($expr instanceof Expr\ArrayDimFetch) {
            return Type::VAR;
        }

        $type = $this->detectTypeOfExpr($expr);
        return $type;
    }

    protected function appendCapturedStmtLinesToContext(array $stmts): void
    {
        foreach ($stmts as $stmt) {
            $this->context->beforeStmtLines[] = $stmt;
        }
    }

    protected function parseBinaryOpPlus(Expr\BinaryOp\Plus $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '+');
    }

    protected function parseBinaryOpMul(Expr\BinaryOp\Mul $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '*');
    }

    protected function parseBinaryOpConcat(Expr\BinaryOp\Concat $expr): string
    {
        return $this->parseFlattenedConcat($expr);
    }

    protected function parseFlattenedConcat(NodeAbstract $expr, array $prefixExpressions = []): string
    {
        $items = [];
        $this->flattenConcatExpr($expr, $items);

        $argList = $prefixExpressions;
        foreach ($items as $item) {
            // Keep one operand so concat still performs PHP string coercion.
            // Prefix expressions are operands too (for example, the left-hand
            // value of `.=`), so an empty RHS literal can be omitted there.
            if ($argList !== [] && $this->isScalarString($item) && $item->value === '') {
                continue;
            }

            $type = $this->detectTypeOfExpr($item);
            $parsed = $this->parseExprAsValue($item);
            $argList[] = $this->prepareConcatOperand($parsed, $type);
        }

        return Symbol::concat() . '({' . implode(', ', $argList) . '})';
    }

    protected function prepareConcatOperand(string $expr, string $type): string
    {
        if (in_array($type, [Type::STR, Type::INT, Type::FLOAT, Type::BOOL], true)) {
            return $expr;
        }

        // Keep conversions of objects/arrays/any values at their original
        // operand position. Moving them into concat() would evaluate all later
        // operands before __toString() or a conversion error is triggered.
        return $this->convertExprToStringByType($expr, $type);
    }

    protected function flattenConcatExpr(NodeAbstract $expr, array &$items): void
    {
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $this->flattenConcatExpr($expr->left, $items);
            $this->flattenConcatExpr($expr->right, $items);
        } else {
            $items[] = $expr;
        }
    }

    protected function parseBinaryOpSmaller(Expr\BinaryOp\Smaller $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '<'));
    }

    protected function parseBinaryOpShiftLeft(Expr\BinaryOp\ShiftLeft $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '<<');
    }

    protected function parseBinaryOpShiftRight(Expr\BinaryOp\ShiftRight $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '>>');
    }

    protected function parseBinaryOpMod(Expr\BinaryOp\Mod $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '%');
    }

    protected function parseBinaryOpGreater(Expr\BinaryOp\Greater $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '>'));
    }

    protected function parseBinaryOpPow(Expr\BinaryOp\Pow $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr->left, 'binary operand');
        $this->assertExprCanBeUsedAsValue($expr->right, 'binary operand');
        $leftType = $this->detectTypeOfExpr($expr->left);
        $rightType = $this->detectTypeOfExpr($expr->right);
        if ($leftType === Type::DECIMAL || $rightType === Type::DECIMAL
            || $leftType === Type::BIGFLOAT || $rightType === Type::BIGFLOAT) {
            $this->fatalError($expr, "Operator '**' is not supported for Decimal or BigFloat; use pow() where supported");
        }
        if ($leftType === Type::BIGINT || $rightType === Type::BIGINT) {
            $leftExpr = $this->parseOrderedOperand($expr->left, false);
            $rightExpr = $this->parseOrderedOperand($expr->right, false);
            if ($leftType !== Type::BIGINT) {
                $leftExpr = $this->convertBigIntExpr($leftExpr, $leftType);
            }
            if ($rightType !== Type::BIGINT) {
                $rightExpr = $this->convertBigIntExpr($rightExpr, $rightType);
            }
            return 'php::BigInt::pow(' . $leftExpr . ', ' . $rightExpr . ')';
        }
        $left  = $this->parseOrderedOperand($expr->left, false);
        $right = $this->parseOrderedOperand($expr->right, false);
        return 'php::fn::pow(' . $left . ', ' . $right . ')';
    }

    protected function parseBinaryOpBitwiseAnd(Expr\BinaryOp\BitwiseAnd $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '&');
    }

    protected function parseBinaryOpBitwiseOr(Expr\BinaryOp\BitwiseOr $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '|');
    }

    protected function parseBinaryOpBitwiseXor(Expr\BinaryOp\BitwiseXor $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '^');
    }

    protected function parseCompareExpr(NodeAbstract $expr): string
    {
        $this->assertExprCanBeUsedAsValue($expr, 'comparison operand');
        // PHPX 与 bool 值比较会出现重载错误，所以需要转换成 bool 值
        if ($this->isScalarBool($expr)) {
            return $this->getBoolValue($expr);
        }
        return $this->parseOrderedOperand($expr, false);
    }

    protected function parseBinaryOpEqual(Expr\BinaryOp\Equal $expr): string
    {
        return $this->genBigNumericCmp($expr, ' == 0')
            ?? 'php::equals(' . $this->parseCompareExpr($expr->left) . ', ' . $this->parseCompareExpr($expr->right) . ')';
    }

    protected function parseBinaryOpNotEqual(Expr\BinaryOp\NotEqual $expr): string
    {
        return $this->genBigNumericCmp($expr, ' != 0')
            ?? '!php::equals(' . $this->parseCompareExpr($expr->left) . ', ' . $this->parseCompareExpr($expr->right) . ')';
    }

    protected function parseBinaryOpIdentical(Expr\BinaryOp $expr): string
    {
        $left  = $this->parseCompareExpr($expr->left);
        $right = $this->parseCompareExpr($expr->right);
        if ($right === 'nullptr') {
            // The left operand may itself be an assignment or another compound
            // expression. Parenthesize it before invoking Variant::isNull(), or
            // C++ binds the member access to the assignment's RHS instead.
            return '(' . $left . ').isNull()';
        }
        if ($optimized = $this->optimizeIdenticalOp($expr->left, $expr->right, $left, $right)) {
            return $optimized;
        }
        return 'php::same(' . $left . ', ' . $right . ')';
    }

    /**
     * Use compile-time type info to optimize === and !== .
     * When both sides are the same narrowed primitive type, emit direct C++ == .
     * When both are narrowed but different types, === is always false.
     */
    private function optimizeIdenticalOp(NodeAbstract $astLeft, NodeAbstract $astRight, string $cppLeft, string $cppRight): ?string
    {
        $primitiveTypes = [Type::INT, Type::FLOAT, Type::BOOL];
        $leftType  = $this->detectTypeOfExpr($astLeft);
        $rightType = $this->detectTypeOfExpr($astRight);

        if ($leftType === null || $rightType === null) {
            return null;
        }
        if (!in_array($leftType, $primitiveTypes, true) || !in_array($rightType, $primitiveTypes, true)) {
            return null;
        }
        if ($leftType === $rightType) {
            if ($leftType === Type::BOOL) {
                $cppLeft = $this->nativeBoolLiteral($astLeft) ?? $cppLeft;
                $cppRight = $this->nativeBoolLiteral($astRight) ?? $cppRight;
            }
            return $cppLeft . ' == ' . $cppRight;
        }
        return 'false';
    }

    /**
     * Strict comparisons between native booleans must use C++ bool literals.
     * parseCompareExpr() normally emits php::true_/php::false_ Variants because
     * dynamic comparisons need zvals, but those wrappers are incorrect once
     * optimizeIdenticalOp() selects a direct primitive comparison.
     */
    private function nativeBoolLiteral(NodeAbstract $expr): ?string
    {
        if (!$this->isScalarBool($expr)) {
            return null;
        }
        return strcasecmp($expr->name->toString(), 'true') === 0 ? 'true' : 'false';
    }

    protected function parseBinaryOpLogicalAnd(Expr\BinaryOp\LogicalAnd|Expr\BinaryOp\BooleanAnd $expr): string
    {
        return $this->parseShortCircuitLogicalOp($expr->left, $expr->right, '&&');
    }

    protected function parseBinaryOpLogicalOr(Expr\BinaryOp\LogicalOr|Expr\BinaryOp\BooleanOr $expr): string
    {
        return $this->parseShortCircuitLogicalOp($expr->left, $expr->right, '||');
    }

    protected function parseShortCircuitLogicalOp(NodeAbstract $left, NodeAbstract $right, string $op): string
    {
        $this->assertExprCanBeUsedAsCondition($left, 'logical operand');
        $this->assertExprCanBeUsedAsCondition($right, 'logical operand');

        $leftExpr = $this->parseNumericIdentifier($left);
        $this->checkVarMustExist($left, $leftExpr);

        $rightBeforeStmtCount = count($this->context->beforeStmtLines);
        $rightAfterStmtCount = count($this->context->afterStmtLines);
        $rightExpr = $this->parseNumericIdentifier($right);
        $rightBeforeStmts = array_slice($this->context->beforeStmtLines, $rightBeforeStmtCount);
        $rightAfterStmts = array_slice($this->context->afterStmtLines, $rightAfterStmtCount);
        $this->context->beforeStmtLines = array_slice($this->context->beforeStmtLines, 0, $rightBeforeStmtCount);
        $this->context->afterStmtLines = array_slice($this->context->afterStmtLines, 0, $rightAfterStmtCount);
        $this->checkVarMustExist($right, $rightExpr);

        $leftBool = $this->convertBoolExpr((string) $leftExpr);
        if (!$rightBeforeStmts && !$rightAfterStmts) {
            return '(' . $leftBool . ' ' . $op . ' ' . $this->convertBoolExpr((string) $rightExpr) . ')';
        }

        $shortCircuitValue = $op === '&&' ? 'false' : 'true';
        $rightCondition = $op === '&&' ? $leftBool : '!(' . $leftBool . ')';

        $code = '[&]() -> bool {';
        $code .= $this->getIndent() . 'if (' . $rightCondition . ') {';
        $code .= $this->formatCapturedStmtLines($rightBeforeStmts);
        if ($rightAfterStmts) {
            $rightTmpVar = $this->addTmpVar(Type::VAR);
            $code .= $this->getIndent() . $rightTmpVar . ' = ' . $rightExpr . ';';
            $code .= $this->formatCapturedStmtLines($rightAfterStmts);
            $rightExpr = $rightTmpVar;
        }
        $code .= $this->getIndent() . 'return ' . $this->convertBoolExpr((string) $rightExpr) . ';';
        $code .= $this->getIndent() . '}';
        $code .= $this->getIndent() . 'return ' . $shortCircuitValue . ';';
        $code .= $this->getIndent() . '}()';

        return $code;
    }

    protected function parseBinaryOpLogicalXor(Expr\BinaryOp\LogicalXor $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '^'));
    }

    protected function parseBinaryOpSmallerOrEqual(Expr\BinaryOp\SmallerOrEqual $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '<='));
    }

    protected function parseBinaryOpGreaterOrEqual(Expr\BinaryOp\GreaterOrEqual $expr): string
    {
        return $this->convertBoolExpr($this->parseBinaryOp($expr->left, $expr->right, '>='));
    }

    protected function parseBinaryOpSpaceship(Expr\BinaryOp\Spaceship $expr): string
    {
        return $this->genBigNumericCmp($expr)
            ?? 'php::compare(' . $this->parseOrderedOperand($expr->left, false) . ', ' . $this->parseOrderedOperand($expr->right, false) . ')';
    }

    protected function genBigNumericCmp(Expr\BinaryOp $expr, string $suffix = ''): ?string
    {
        $leftType = $this->detectTypeOfExpr($expr->left);
        $rightType = $this->detectTypeOfExpr($expr->right);

        $bigTypes = [Type::BIGINT, Type::DECIMAL, Type::BIGFLOAT];
        if (in_array($leftType, $bigTypes, true) && in_array($rightType, $bigTypes, true)
            && $leftType !== $rightType) {
            $this->fatalError(
                $expr,
                'Cannot compare different Big* types implicitly; convert both operands to the same type explicitly'
            );
        }

        if ($leftType === Type::BIGFLOAT || $rightType === Type::BIGFLOAT) {
            $leftExpr = $this->parseOrderedOperand($expr->left, false);
            $rightExpr = $this->parseOrderedOperand($expr->right, false);
            if ($leftType !== Type::BIGFLOAT) {
                $leftExpr = $this->convertBigFloatExpr($leftExpr, $leftType);
            }
            if ($rightType !== Type::BIGFLOAT) {
                $rightExpr = $this->convertBigFloatExpr($rightExpr, $rightType);
            }
            return 'php::BigFloat::cmp(' . $leftExpr . ', ' . $rightExpr . ')' . $suffix;
        }
        if ($leftType === Type::BIGINT || $rightType === Type::BIGINT) {
            $leftExpr = $this->parseOrderedOperand($expr->left, false);
            $rightExpr = $this->parseOrderedOperand($expr->right, false);
            if ($leftType !== Type::BIGINT) {
                $leftExpr = $this->convertBigIntExpr($leftExpr, $leftType);
            }
            if ($rightType !== Type::BIGINT) {
                $rightExpr = $this->convertBigIntExpr($rightExpr, $rightType);
            }
            return 'php::BigInt::cmp(' . $leftExpr . ', ' . $rightExpr . ')' . $suffix;
        }
        if ($leftType === Type::DECIMAL || $rightType === Type::DECIMAL) {
            $leftExpr = $this->parseOrderedOperand($expr->left, false);
            $rightExpr = $this->parseOrderedOperand($expr->right, false);
            if ($leftType !== Type::DECIMAL) {
                $leftExpr = $this->convertDecimalExpr($leftExpr, $leftType, $expr->left);
            }
            if ($rightType !== Type::DECIMAL) {
                $rightExpr = $this->convertDecimalExpr($rightExpr, $rightType, $expr->right);
            }
            return 'php::Decimal::cmp(' . $leftExpr . ', ' . $rightExpr . ')' . $suffix;
        }

        return null;
    }

    protected function parseBinaryOpCoalesce(Expr\BinaryOp\Coalesce $expr): string
    {
        return $this->parseValueSelection($expr, $expr->left, $expr->right, self::OP_ISSET);
    }

    protected function parseBinaryOpNotIdentical(Expr\BinaryOp $expr): string
    {
        return '!(' . $this->parseBinaryOpIdentical($expr) . ')';
    }

    protected function parseBinaryOpDiv(Expr\BinaryOp\Div $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '/');
    }

    protected function guardLiteralDivisionByZero(NodeAbstract $right, string $op): void
    {
        if (($op === '/' or $op === '%' or $op === '/=' or $op === '%=') and $this->isZeroLiteral($right)) {
            $this->fatalError($right, 'Cannot divide or modulo by zero');
        }
    }

    protected function parseBinaryOpMinus(Expr\BinaryOp\Minus $expr): string
    {
        return $this->parseBinaryOp($expr->left, $expr->right, '-');
    }

}
