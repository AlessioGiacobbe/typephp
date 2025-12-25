<?php

namespace PhpAot\Php;

class FunctionDef
{
    public string $name;
    public array $arguments;
    public string $returnType;
    public int $argumentCountRequired = 0;
}