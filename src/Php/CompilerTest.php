<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

class CompilerTest extends Translator
{
    protected static ?self $instance = null;

    public static function getInstance(string $rootPath = ''): CompilerTest
    {
        if (!self::$instance) {
            self::$instance = new self($rootPath);
            self::$instance->forTest = true;
        }
        return self::$instance;
    }
}
