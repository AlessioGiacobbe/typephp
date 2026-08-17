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
    /** The declared type is the unconstrained `mixed`/`any` type. */
    public bool $explicitMixed = false;
    public string $class = '';
    public array $typeCheck = [];
    public string $typeStr = '';
    public bool $promoted = false;
    public bool $readonly = false;
    public ?string $getter = null;
    public ?string $setter = null;
    public bool $virtual = false;

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

    public function isReadonly(): bool
    {
        return $this->readonly;
    }

    public function isPrivateSet(): bool
    {
        return (bool) ($this->flags & Modifiers::PRIVATE_SET);
    }

    public function isProtectedSet(): bool
    {
        return (bool) ($this->flags & Modifiers::PROTECTED_SET);
    }
}
