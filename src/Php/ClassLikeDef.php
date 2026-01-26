<?php

namespace PhpAot\Php;

class ClassLikeDef
{
    public string $name;
    public string $namespace;
    public string $extends = '';


    public function __construct(string $name, string $namespace = '')
    {
        $this->name = $name;
        $this->namespace = $namespace;
    }

    public function getNamespacedName(bool $symbolic = true): string
    {
        if ($this->namespace === '') {
            return $this->name;
        }
        if ($symbolic) {
            return str_replace('\\', '_', $this->namespace . '_' . $this->name);
        }
        return $this->namespace . '\\\\' . $this->name;
    }
}