<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

class Symbol
{
    public static function getStaticProperty(): string
    {
        return 'php::getStaticProperty';
    }

    public static function setStaticProperty(): string
    {
        return 'php::setStaticProperty';
    }

    public static function getCalledCe(): string
    {
        return CompilerBase::PREFIX . 'get_called_ce(this_)';
    }

    public static function getCalledClass(): string
    {
        return CompilerBase::PREFIX . 'get_called_class(this_)';
    }

    public static function constant(): string
    {
        return 'php::constant';
    }
}
