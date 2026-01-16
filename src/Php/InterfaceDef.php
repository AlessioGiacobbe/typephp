<?php

namespace PhpAot\Php;

class InterfaceDef extends ClassLikeDef
{
    public function __construct(string $name, string $namespace = '')
    {
        parent::__construct($name, $namespace);
    }
}