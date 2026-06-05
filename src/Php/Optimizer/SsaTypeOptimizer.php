<?php
/**
 * SSA-based type narrowing optimizer.
 *
 * Uses SSA/e-SSA analysis to narrow local variable types from php::Var
 * to C++ native types (php::Int, php::Float) when all definitions
 * provably produce the same type and no dangerous operations exist.
 */

namespace PhpAot\Php\Optimizer;

use PhpAot\Php\Analysis\SsaBuilder;
use PhpAot\Php\Analysis\SsaFlags;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;

trait SsaTypeOptimizer
{
    /**
     * Binary ops that always produce int from int operands and never overflow
     * to float. Bitwise operations and modulo are safe; arithmetic is not.
     */
    private const SAFE_INT_BINARY_OPS = [
        'Expr_BinaryOp_BitwiseAnd' => true,
        'Expr_BinaryOp_BitwiseOr' => true,
        'Expr_BinaryOp_BitwiseXor' => true,
        'Expr_BinaryOp_ShiftLeft' => true,
        'Expr_BinaryOp_ShiftRight' => true,
        'Expr_BinaryOp_Mod' => true,
    ];

    /**
     * Compound assignment ops that keep the variable int-typed.
     */
    private const SAFE_INT_ASSIGN_OPS = [
        'Expr_AssignOp_BitwiseAnd' => true,
        'Expr_AssignOp_BitwiseOr' => true,
        'Expr_AssignOp_BitwiseXor' => true,
        'Expr_AssignOp_ShiftLeft' => true,
        'Expr_AssignOp_ShiftRight' => true,
        'Expr_AssignOp_Mod' => true,
    ];

    /**
     * Use SSA analysis to narrow local variable types to C++ native types.
     *
     * For each variable, if ALL definitions produce the same narrow type
     * (int, float), and the variable is never referenced, escaped,
     * killed, or defined by a φ function with mixed sources, pre-set the
     * type in localVars so genScopeVarDecl emits the narrow C++ type.
     */
    protected function optimizeVarTypes(): void
    {
        $ssa = $this->context->ssaBuilder;
        if (!$ssa || empty($ssa->ssaVars)) {
            return;
        }

        $narrowableTypes = [
            self::TYPE_INT => true,
            self::TYPE_FLOAT => true,
        ];

        // Group SSA vars by original variable name
        $groups = [];
        foreach ($ssa->ssaVars as $ssaVar) {
            $name = $ssaVar->origName;
            if (!isset($groups[$name])) {
                $groups[$name] = [];
            }
            $groups[$name][] = $ssaVar;
        }

        foreach ($groups as $varName => $varList) {
            $varName = $this->escapeVarName($varName);
            // Skip parameters — they already have declared types
            if (isset($this->context->arguments[$varName])) {
                continue;
            }

            // Skip if any SSA var has dangerous flags
            $hasPhi = false;
            $hasDanger = false;
            $narrowedType = null;

            $nonNarrowableType = null;

            foreach ($varList as $ssaVar) {
                if ($ssaVar->flags & SsaFlags::PHI) {
                    $hasPhi = true;
                    continue;
                }
                if ($ssaVar->flags & (SsaFlags::REFERENCE | SsaFlags::ESCAPED | SsaFlags::KILLED)) {
                    $hasDanger = true;
                    break;
                }

                $defType = $this->detectSsaDefType($ssaVar);
                if ($defType === null) {
                    $hasDanger = true;
                    break;
                }
                // Non-narrowable types (BigInt/BigFloat/Decimal/Stream/objects etc.)
                // are not dangerous — they just can't be narrowed. Record the type
                // so dependent SSA variables can resolve it later.
                if (!isset($narrowableTypes[$defType])) {
                    if ($nonNarrowableType === null) {
                        $nonNarrowableType = $defType;
                    } elseif ($nonNarrowableType !== $defType) {
                        // Mixed non-narrowable types — can't determine a single type
                        $nonNarrowableType = self::TYPE_VAR;
                    }
                    continue;
                }

                if ($narrowedType === null) {
                    $narrowedType = $defType;
                } elseif ($narrowedType !== $defType) {
                    $hasDanger = true;
                    break;
                }
            }

            if ($hasDanger) {
                continue;
            }

            // Mixed narrowable and non-narrowable types (e.g. $x = [1,2] then $x = 42)
            // — can't safely narrow.
            if ($narrowedType !== null && $nonNarrowableType !== null && $nonNarrowableType !== self::TYPE_VAR) {
                continue;
            }

            if ($narrowedType === null) {
                // No narrowable type found, but register Big* types so dependent
                // SSA variables can resolve them (these types have no extra metadata).
                if (
                    $nonNarrowableType !== null
                    && in_array($nonNarrowableType, [self::TYPE_BIGINT, self::TYPE_DECIMAL, self::TYPE_BIGFLOAT, self::TYPE_STREAM], true)
                ) {
                    $this->context->localVars[$varName] = $nonNarrowableType;
                }
                continue;
            }

            // Scan for operations that SSA definition types alone can't detect
            $functionStmts = $this->context->ssaBuilder->getStmts();
            if ($functionStmts) {
                if ($narrowedType === self::TYPE_INT && $this->hasDangerousIntOps($varName, $functionStmts)) {
                    continue;
                }
                if ($narrowedType === self::TYPE_FLOAT && $this->hasDangerousFloatOps($varName, $functionStmts)) {
                    continue;
                }
            }

            // Check φ-function sources for mixed types
            if ($hasPhi && $this->phiSourcesHaveMixedTypes($ssa, $varList, $narrowedType)) {
                continue;
            }

            // Apply narrowing — bypass getNativeType(): SSA has PROVEN the type.
            $this->context->localVars[$varName] = $narrowedType;
        }
    }

    /**
     * Detect the type produced by a single SSA variable definition.
     * Returns null if the type cannot be determined from the AST.
     */
    private function detectSsaDefType(\PhpAot\Php\Analysis\SsaVar $ssaVar): ?string
    {
        $def = $ssaVar->definition;
        if (!$def) {
            return null;
        }

        if ($def instanceof Node\Stmt\Expression && $def->expr instanceof Node\Expr\Assign) {
            $expr = $def->expr->expr;
            $type = $this->detectTypeOfExpr($expr);
            if ($type === self::TYPE_INT && $this->exprCanOverflowInt($expr)) {
                return null;
            }
            return $type;
        }

        if ($def instanceof Node\Stmt\Foreach_) {
            return null;
        }

        if ($def instanceof Node\Stmt\Catch_) {
            return self::TYPE_OBJECT;
        }

        if ($def instanceof Node\Stmt\Static_) {
            foreach ($def->vars as $staticVar) {
                if ($staticVar->var->name === $ssaVar->origName && $staticVar->default) {
                    return $this->detectTypeOfExpr($staticVar->default);
                }
            }
            return null;
        }

        return null;
    }

    /**
     * Check whether a TYPE_INT expression could overflow int64 at runtime
     * and produce a float in PHP.
     */
    private function exprCanOverflowInt(NodeAbstract $expr): bool
    {
        // Division always produces float in PHP when operands are int
        if ($expr instanceof Node\Expr\BinaryOp\Div) {
            return true;
        }

        if ($expr instanceof Node\Expr\BinaryOp\Plus
            || $expr instanceof Node\Expr\BinaryOp\Minus
            || $expr instanceof Node\Expr\BinaryOp\Mul) {
            $leftConst = $this->getIntConstantValue($expr->left);
            $rightConst = $this->getIntConstantValue($expr->right);

            if ($leftConst !== null && $rightConst !== null) {
                $result = match (true) {
                    $expr instanceof Node\Expr\BinaryOp\Plus => $leftConst + $rightConst,
                    $expr instanceof Node\Expr\BinaryOp\Minus => $leftConst - $rightConst,
                    $expr instanceof Node\Expr\BinaryOp\Mul => $leftConst * $rightConst,
                };
                return $result > PHP_INT_MAX || $result < PHP_INT_MIN;
            }

            if ($this->isBoundaryConstant($expr->left)
                || $this->isBoundaryConstant($expr->right)) {
                return true;
            }

            return false;
        }

        // ** can easily overflow (e.g. 2**63 already exceeds INT64_MAX)
        if ($expr instanceof Node\Expr\BinaryOp\Pow) {
            return true;
        }

        if ($expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && strtolower($expr->name->toString()) === 'pow') {
            return true;
        }

        if ($expr instanceof Node\Expr\BinaryOp) {
            return $this->exprCanOverflowInt($expr->left)
                || $this->exprCanOverflowInt($expr->right);
        }

        return false;
    }

    private function getIntConstantValue(NodeAbstract $node): ?int
    {
        if ($node instanceof Node\Scalar\LNumber) {
            return $node->value;
        }
        return null;
    }

    private function isBoundaryConstant(NodeAbstract $node): bool
    {
        if ($node instanceof Node\Expr\ConstFetch
            && $node->name instanceof Node\Name) {
            $name = $node->name->toLowerString();
            return $name === 'php_int_max' || $name === 'php_int_min';
        }
        return false;
    }

    private function phiSourcesHaveMixedTypes(SsaBuilder $ssa, array $varList, string $narrowedType): bool
    {
        foreach ($varList as $ssaVar) {
            if (($ssaVar->flags & SsaFlags::PHI) && !empty($ssaVar->phiSources)) {
                foreach ($ssaVar->phiSources as $srcSsaId) {
                    if (isset($ssa->ssaVars[$srcSsaId])) {
                        $srcVar = $ssa->ssaVars[$srcSsaId];
                        $srcType = $this->detectSsaDefType($srcVar);
                        if ($srcType !== null && $srcType !== $narrowedType) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }

    /**
     * Check whether $varName is used in any operation that would produce
     * incorrect results with a narrowed C++ int64_t. Only bitwise ops
     * (& | ^ << >> ~) and modulo (%) are safe — arithmetic can overflow
     * to float, which int64_t would wrap instead.
     */
    private function hasDangerousIntOps(string $varName, array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($this->scanStmtForDangerousIntOps($stmt, $varName)) {
                return true;
            }
        }
        return false;
    }

    private function scanStmtForDangerousIntOps($stmt, string $varName): bool
    {
        if (!$stmt instanceof Node) {
            return false;
        }

        // Check conditions in control-flow statements
        if (($stmt instanceof Node\Stmt\If_
            || $stmt instanceof Node\Stmt\While_
            || $stmt instanceof Node\Stmt\Do_)
            && $stmt->cond instanceof Node
            && $this->exprHasIntHazard($stmt->cond, $varName)) {
            return true;
        }

        if ($stmt instanceof Node\Stmt\For_) {
            foreach ($stmt->cond as $cond) {
                if ($cond instanceof Node && $this->exprHasIntHazard($cond, $varName)) {
                    return true;
                }
            }
            foreach ($stmt->init as $init) {
                if ($init instanceof Node && $this->exprHasIntHazard($init, $varName)) {
                    return true;
                }
            }
            foreach ($stmt->loop as $loop) {
                if ($loop instanceof Node && $this->exprHasIntHazard($loop, $varName)) {
                    return true;
                }
            }
        }

        if ($stmt instanceof Node\Stmt\Foreach_ && $stmt->expr instanceof Node) {
            if ($this->exprHasIntHazard($stmt->expr, $varName)) {
                return true;
            }
        }

        if ($stmt instanceof Node\Stmt\Switch_ && $stmt->cond instanceof Node) {
            if ($this->exprHasIntHazard($stmt->cond, $varName)) {
                return true;
            }
        }

        if ($stmt instanceof Node\Stmt\Expression) {
            if ($this->exprHasIntHazard($stmt->expr, $varName)) {
                return true;
            }
        }

        if ($stmt instanceof Node\Stmt\Return_ && $stmt->expr instanceof Node) {
            if ($this->exprHasIntHazard($stmt->expr, $varName)) {
                return true;
            }
        }

        if ($stmt instanceof Node\Stmt\Echo_) {
            foreach ($stmt->exprs as $expr) {
                if ($expr instanceof Node && $this->exprHasIntHazard($expr, $varName)) {
                    return true;
                }
            }
        }

        return $this->recurseForDangerousOps($stmt, $varName, 'Int');
    }

    /**
     * Recursively check an expression for hazardous int usage.
     *
     * Only bitwise ops (& | ^ << >> ~) and modulo (%) are safe for a
     * narrowed int64_t. Any other BinaryOp, AssignOp, UnaryMinus, or
     * Inc/Dec that involves $varName is a hazard.
     */
    private function exprHasIntHazard($expr, string $varName): bool
    {
        if (!$expr instanceof Node) {
            return false;
        }

        $type = $expr->getType();

        // Safe binary ops: bitwise + modulo — recurse into children
        if (isset(self::SAFE_INT_BINARY_OPS[$type])) {
            return $this->exprHasIntHazard($expr->left, $varName)
                || $this->exprHasIntHazard($expr->right, $varName);
        }

        // Unsafe binary op: flag if $varName appears anywhere inside
        if ($expr instanceof Node\Expr\BinaryOp) {
            if ($this->exprUsesVar($expr, $varName)) {
                return true;
            }
            return $this->exprHasIntHazard($expr->left, $varName)
                || $this->exprHasIntHazard($expr->right, $varName);
        }

        // Safe compound assignment: $varName &= ..., etc. — recurse into RHS
        if (isset(self::SAFE_INT_ASSIGN_OPS[$type])) {
            return $this->exprHasIntHazard($expr->expr, $varName);
        }

        // Unsafe compound assignment: $varName += ..., etc.
        if ($expr instanceof Node\Expr\AssignOp) {
            if ($this->isVarNamed($expr->var, $varName)) {
                return true;
            }
            return $this->exprHasIntHazard($expr->expr, $varName);
        }

        // Assignment: $var = rhs — LHS is a definition, safe; check RHS
        if ($expr instanceof Node\Expr\Assign) {
            return $this->exprHasIntHazard($expr->expr, $varName);
        }

        // ~$varName is always int, safe
        if ($expr instanceof Node\Expr\BitwiseNot) {
            return $this->exprHasIntHazard($expr->expr, $varName);
        }

        // -$varName can overflow INT_MIN → float
        if ($expr instanceof Node\Expr\UnaryMinus) {
            if ($this->exprUsesVar($expr, $varName)) {
                return true;
            }
            return false;
        }

        // $varName++, ++$varName, $varName--, --$varName can overflow
        if ($expr instanceof Node\Expr\PreInc || $expr instanceof Node\Expr\PreDec
            || $expr instanceof Node\Expr\PostInc || $expr instanceof Node\Expr\PostDec) {
            return $this->isVarNamed($expr->var, $varName);
        }

        // (int)$varName — safe type read
        if ($expr instanceof Node\Expr\Cast\Int_) {
            return $this->exprHasIntHazard($expr->expr, $varName);
        }

        // Generic recursion over common expression sub-nodes
        foreach (['left', 'right', 'expr', 'var', 'cond', 'if', 'else', 'dim', 'value'] as $prop) {
            if (isset($expr->$prop) && $expr->$prop instanceof Node) {
                if ($this->exprHasIntHazard($expr->$prop, $varName)) {
                    return true;
                }
            }
        }

        foreach (['args', 'exprs', 'items', 'stmts'] as $prop) {
            if (isset($expr->$prop) && is_array($expr->$prop)) {
                foreach ($expr->$prop as $item) {
                    if ($item instanceof Node) {
                        if ($this->exprHasIntHazard($item, $varName)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check whether $varName appears anywhere recursively in an AST node.
     */
    private function exprUsesVar($node, string $varName): bool
    {
        if (!$node instanceof Node) {
            return false;
        }

        if ($this->isVarNamed($node, $varName)) {
            return true;
        }

        foreach (['left', 'right', 'expr', 'var', 'cond', 'if', 'else', 'dim', 'value'] as $prop) {
            if (isset($node->$prop) && $node->$prop instanceof Node) {
                if ($this->exprUsesVar($node->$prop, $varName)) {
                    return true;
                }
            }
        }

        foreach (['args', 'exprs', 'items', 'stmts'] as $prop) {
            if (isset($node->$prop) && is_array($node->$prop)) {
                foreach ($node->$prop as $item) {
                    if ($item instanceof Node) {
                        if ($this->exprUsesVar($item, $varName)) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }

    private function isVarNamed($node, string $varName): bool
    {
        return $node instanceof Node\Expr\Variable
            && is_string($node->name)
            && $node->name === $varName;
    }

    private function hasDangerousFloatOps(string $varName, array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($this->scanStmtForDangerousFloatOps($stmt, $varName)) {
                return true;
            }
        }
        return false;
    }

    private function scanStmtForDangerousFloatOps($stmt, string $varName): bool
    {
        if (!$stmt instanceof Node) {
            return false;
        }

        if ($stmt instanceof Node\Stmt\Expression && $stmt->expr instanceof Expr\AssignOp) {
            $lhs = $stmt->expr->var;
            if ($lhs instanceof Expr\Variable && is_string($lhs->name) && $lhs->name === $varName) {
                if ($stmt->expr instanceof Expr\AssignOp\BitwiseAnd
                    || $stmt->expr instanceof Expr\AssignOp\BitwiseOr
                    || $stmt->expr instanceof Expr\AssignOp\BitwiseXor
                    || $stmt->expr instanceof Expr\AssignOp\ShiftLeft
                    || $stmt->expr instanceof Expr\AssignOp\ShiftRight
                    || $stmt->expr instanceof Expr\AssignOp\Mod) {
                    return true;
                }
            }
        }

        return $this->recurseForDangerousOps($stmt, $varName, 'Float');
    }

    /**
     * Recurse into compound statements (if/else, foreach, while, for, try/catch, switch).
     */
    private function recurseForDangerousOps($stmt, string $varName, string $mode): bool
    {
        $method = 'hasDangerous' . $mode . 'Ops';

        if ($stmt instanceof Node\Stmt\If_) {
            if ($this->$method($varName, $stmt->stmts)) return true;
            if (!empty($stmt->elseifs)) {
                foreach ($stmt->elseifs as $elseif) {
                    if ($this->$method($varName, $elseif->stmts)) return true;
                }
            }
            if ($stmt->else && $this->$method($varName, $stmt->else->stmts)) return true;
        }

        if ($stmt instanceof Node\Stmt\Foreach_) {
            if ($this->$method($varName, $stmt->stmts)) return true;
        }

        if ($stmt instanceof Node\Stmt\While_ || $stmt instanceof Node\Stmt\Do_) {
            if ($this->$method($varName, $stmt->stmts)) return true;
        }

        if ($stmt instanceof Node\Stmt\For_) {
            if ($this->$method($varName, $stmt->stmts)) return true;
        }

        if ($stmt instanceof Node\Stmt\TryCatch) {
            if ($this->$method($varName, $stmt->stmts)) return true;
            foreach ($stmt->catches as $catch) {
                if ($this->$method($varName, $catch->stmts)) return true;
            }
            if ($stmt->finally && $this->$method($varName, $stmt->finally->stmts)) return true;
        }

        if ($stmt instanceof Node\Stmt\Case_ || $stmt instanceof Node\Stmt\Switch_) {
            if (isset($stmt->stmts) && $this->$method($varName, $stmt->stmts)) return true;
        }

        return false;
    }
}
