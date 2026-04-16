<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

namespace PhpAot\Php;

class Constants
{
    public const array CPP_RESERVED_NAMES = [
        'auto',
        'break',
        'case',
        'catch',
        'class',
        'struct',
        'const',
        'continue',
        'default',
        'do',
        'else',
        'elseif',
        'enum',
        'extends',
        'final',
        'finally',
        'for',
        'function',
        'global',
        'if',
        'bool',
        'int',
        'double',
        'float',
        'false',
        'for',
        'if',
        'int',
        'new',
        'null',
        'var',
        'char',
        'or',
        'and',
        'private',
        'protected',
        'public',
        'return',
        'static',
        'pipe',
        'template',
        'namespace',
        'errno', // Linux error code
        'this_', // phpx keywords
    ];

    public const UNSUPPORTED_FUNCTIONS = [
        'extract',
    ];
}
