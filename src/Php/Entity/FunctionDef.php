<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php\Entity;

use PhpAot\Php\ArgInfo;

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
    public bool $completed = false;

    public function __construct(string $name, string $returnType, string $namespace, bool $stub)
    {
        $this->name = $name;
        $this->returnType = $returnType;
        $this->namespace = $namespace;
        $this->stub = $stub;
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
