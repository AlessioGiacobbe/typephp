<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Context;

class FunctionContext
{
    /**
     * @var array<string, string>
     */
    public array $objects = [];

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

    /**
     * 赋值表达式的左值，写操作，右值为读操作.
     */
    public bool $inAssignExpr = false;
    public array $beforeStmtLines = [];
    public array $afterStmtLines = [];
    public array $objectProps;
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
        $this->stdArrays = [];
        $this->stdContainers = [];
        $this->objectProps = [];
        $this->ceWrappers = [];
        $this->tmpVarIndex = 0;
        $this->scopeLayouts = [];
        $this->scopeLevel = 0;
        $this->inLoop = false;
        $this->inClosure = false;
        $this->inAssignExpr = false;
    }

    public function enterScope(): void
    {
        $this->scopeLayouts[$this->scopeLevel] = new ScopeContext();
        $this->scopeLevel++;
    }

    public function leaveScope(): void
    {
        unset($this->scopeLayouts[$this->scopeLevel]);
        $this->scopeLevel--;
    }
}
