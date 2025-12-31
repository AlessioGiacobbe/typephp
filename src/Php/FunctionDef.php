<?php

namespace PhpAot\Php;

class FunctionDef
{
    public string $name;
    public array $argInfoList = [];
    public string $params;
    public string $returnType;
    public int $argCountRequired = 0;
}