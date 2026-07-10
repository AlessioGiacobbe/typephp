<?php
/**
 * This file is part of TypePHP.
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace TypePhp\Entity;

use PhpParser\Modifiers;

class PropertyDef
{
    public string $name;
    public string $type;
    public int $flags;
    public ?string $default = null;
    public ?ArrayInitPlan $arrayInitPlan = null;
    public bool $nullable = false;
    public string $class = '';
    public array $typeCheck = [];
    public string $typeStr = '';
    public bool $promoted = false;

    public function __construct(string $name, int $flags, string $type, ?string $default = null, bool $nullable = false)
    {
        $this->flags   = $flags;
        $this->name    = $name;
        $this->type    = $type;
        $this->default = $default;
        $this->nullable = $nullable;
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

    public function isStatic(): bool
    {
        return $this->flags & Modifiers::STATIC;
    }
}
