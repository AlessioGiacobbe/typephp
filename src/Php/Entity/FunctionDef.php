<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Entity;

use PhpAot\Php\ArgInfo;
use PhpParser\NodeAbstract;

class FunctionDef
{
    public string $name;
    public string $returnType;

    /**
     * @var array<ArgInfo>
     */
    public array $argInfoList = [];
    public int $argCountRequired = 0;
    public string $params = '';
    public string $namespace;
    public bool $method = false;
    public bool $stub = false;

    /**
     * @var string 必须是带有命名空间的完整类名
     */
    public string $returnClass = '';

    /** Same format as ArgInfo::$typeCheck. Null means no runtime return type check. */
    public ?array $returnTypeCheck = null;

    /** Human-readable return type string for error messages. */
    public string $returnTypeStr = '';

    /** Original union/nullable return type AST node. */
    public ?NodeAbstract $returnTypeNode = null;

    public function __construct(string $name, string $returnType, string $namespace)
    {
        $this->name = $name;
        $this->returnType = $returnType;
        $this->namespace = $namespace;
    }

    public function getNamespacedName(): string
    {
        return $this->namespace ? $this->namespace . '\\' . $this->name : $this->name;
    }

    public function hasVariadicArg(): bool
    {
        return $this->argInfoList && $this->argInfoList[count($this->argInfoList) - 1]->variadic;
    }
}
