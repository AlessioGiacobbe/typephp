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
    public bool $method = false;

    public function __construct(string $name, string $returnType)
    {
        $this->name       = $name;
        $this->returnType = $returnType;
    }
}
