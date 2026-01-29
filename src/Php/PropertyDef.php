<?php

namespace PhpAot\Php;

use PhpParser\Modifiers;

class PropertyDef
{
    public string $name;
    public string $type;
    public int $flags;
    public ?string $default = null;

    public function __construct(string $name, int $flags, string $type, ?string $default = null)
    {
        $this->flags = $flags;
        $this->name = $name;
        $this->type = $type;
        $this->default = $default;
    }

    public function isPrivate(): bool
    {
        return $this->flags & Modifiers::PRIVATE;
    }

    public function isProtected(): bool
    {
        return $this->flags & Modifiers::PROTECTED;
    }

    public function isPublic(): bool
    {
        return !$this->isPrivate() && !$this->isProtected();
    }
}
