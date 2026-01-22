<?php

namespace PhpAot\Php;

class FunctionDef
{
    public string $name;
    public string $returnType;
    public array $argInfoList = [];
    public int $argCountRequired = 0;
    public string $params = '';
    public bool $method = false;


    public function __construct(string $name, string $returnType)
    {
        $this->name = $name;
        $this->returnType = $returnType;
    }
}
