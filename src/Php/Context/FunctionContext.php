<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Context;

use PhpAot\Php\Analysis\SsaBuilder;

class FunctionContext
{
    /** SSA builder for the current function. Built once per function, discarded with the context. */
    public ?SsaBuilder $ssaBuilder = null;

    /** Map of SSA-stable object variable name => class name (SsaPropOptimizer). */
    public array $stableObjects = [];

    /** Map of hoisted property refs: objName => [propName => true] (SsaPropOptimizer). */
    public array $hoistedProps = [];

    /** Map of properties that must not be hoisted: objName => [propName|'*' => true] (SsaPropOptimizer). */
    public array $unsafeObjectProps = [];

    /**
     * @var array<string, string>
     */
    public array $objects = [];

    /**
     * Declared object constraints that are not used for native-call dispatch.
     *
     * @var array<string, string>
     */
    public array $declaredObjects = [];

    /**
     * @var array<string, array>
     */
    public array $stdArrays = [];

    /**
     * @var array<string, array>
     */
    public array $stdContainers = [];
    public array $localVars = [];
    public array $staticVars = [];
    public array $globalVars = [];

    /**
     * @var array<string, string>
     */
    public array $ceWrappers = [];
    public int $tmpVarIndex = 0;
    public array $arguments = [];
    public bool $inLoop = false;
    public bool $inClosure = false;
    public ?array $closureReturnTypeCheck = null;
    public string $closureReturnTypeStr = '';

    /** True if any break N (N > 1) appears in this function. */
    public bool $hasMultiLevelBreak = false;

    /** True if any continue N (N > 1) appears in this function. */
    public bool $hasMultiLevelContinue = false;

    public array $beforeStmtLines = [];
    public array $afterStmtLines = [];
    public array $objectProps;
    /** Map of static property local slots. int/float keep stable zval* slots; other types use Var slots. */
    public array $staticPropRefs = [];
    public int $scopeLevel = 0;
    /**
     * @var array<int, ScopeContext>
     */
    public array $scopeLayouts = [];

    public function __construct()
    {
        $this->localVars = [];
        $this->staticVars = [];
        $this->arguments = [];
        $this->objects = [];
        $this->declaredObjects = [];
        $this->stdArrays = [];
        $this->stdContainers = [];
        $this->objectProps = [];
        $this->ssaBuilder = null;
        $this->stableObjects = [];
        $this->hoistedProps = [];
        $this->unsafeObjectProps = [];
        $this->staticPropRefs = [];
        $this->ceWrappers = [];
        $this->tmpVarIndex = 0;
        $this->scopeLayouts = [];
        $this->scopeLevel = 0;
        $this->inLoop = false;
        $this->inClosure = false;
        $this->closureReturnTypeCheck = null;
        $this->closureReturnTypeStr = '';
    }

    public function enterScope(): void
    {
        $this->scopeLayouts[$this->scopeLevel] = new ScopeContext();
        $this->scopeLevel++;
    }

    public function leaveScope(): void
    {
        $this->scopeLevel--;
        unset($this->scopeLayouts[$this->scopeLevel]);
    }

    public function resetAnalysisTemporaries(array $localVars, int $tmpVarIndex, array $declaredObjects): void
    {
        $this->localVars = $localVars;
        $this->tmpVarIndex = $tmpVarIndex;
        $this->declaredObjects = $declaredObjects;
        $this->beforeStmtLines = [];
        $this->afterStmtLines = [];
        $this->objectProps = [];
        $this->hoistedProps = [];
        $this->staticPropRefs = [];
        $this->scopeLayouts = [];
        $this->scopeLevel = 0;
        $this->inLoop = false;
    }
}
