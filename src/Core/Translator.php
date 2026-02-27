<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Core;

abstract class Translator
{
    public string $mode = 'cli';
    protected int $indentLevel = 0;
    protected string $indentStr = "\t";
    protected string $lang;

    public function setMode($mode): void
    {
        $this->mode = $mode;
    }

    public function setIndent(string $indent): void
    {
        $this->indentStr = $indent;
    }

    public function setIndentLevel(int $level): void
    {
        $this->indentLevel = $level;
    }

    public function getLang(): string
    {
        return $this->lang;
    }

    abstract public function getLine($node): int;

    abstract public function getType($node): string;

    protected function getIndent(): string
    {
        return str_repeat($this->indentStr, $this->indentLevel);
    }
}
