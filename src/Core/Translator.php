<?php

namespace PhpAot\Core;

abstract class Translator
{
    protected int $indentLevel = 0;
    protected string $indentStr = "\t";
    public string $mode = 'cli';
    protected string $lang;

    public function setMode($mode): void
    {
        $this->mode = $mode;
    }

    public function setIndent(string $indent): void
    {
        $this->indentStr = $indent;
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
