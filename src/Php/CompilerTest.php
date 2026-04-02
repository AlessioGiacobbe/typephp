<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

/**
 * @internal
 * @coversNothing
 */
class CompilerTest extends Translator
{
    public static function create(string $rootPath = ''): CompilerTest
    {
        $instance = new self($rootPath);
        $instance->forTest = true;
        return $instance;
    }
}
