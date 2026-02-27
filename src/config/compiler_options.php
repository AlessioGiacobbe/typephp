<?php
/**
 * This file is part of Swoole-Compiler(AOT).
 *
 * @link     https://www.swoole.com/
 * @contact  service@swoole.com
 */

return [
    'optimize' => [
        'prefix'      => 'O',
        'longPrefix'  => 'optimize',
        'description' => 'Set the optimization level of the gcc compiler to 0 by default',
        'required'    => false,
        'castTo'      => 'int',
        'defaultValue' => 0,
    ],
    'output' => [
        'prefix'      => 'o',
        'longPrefix'  => 'output',
        'description' => 'Output file',
    ],
    'help' => [
        'prefix'      => 'h',
        'longPrefix'  => 'help',
        'description' => 'Show help',
        'noValue'     => true,
    ],
    'profile' => [
        'longPrefix'  => 'profile',
        'description' => 'Enable performance profiling',
        'required'    => false,
        'noValue'     => true,
    ],
    'noLiteralStrings' => [
        'longPrefix'  => 'no-literal-strings',
        'description' => 'Disable literal strings optimization',
        'required'    => false,
        'noValue'     => true,
    ],
    'force' => [
        'prefix'      => 'f',
        'longPrefix'  => 'force',
        'description' => 'Force compile even if cache exists',
        'required'    => false,
        'noValue'     => true,
    ],
    'mode' => [
        'longPrefix'  => 'mode',
        'prefix'      => 'm',
        'description' => 'Build mode, -m bin(binary) or -m ext(extension), default: bin',
        'required' => false,
        'defaultValue' => 'bin',
    ],
    'debug-line' => [
        'longPrefix' => 'debug-line',
        'description' => 'Enable debug line',
        'required' => false,
        'defaultValue' => 0,
    ],
    'debug-info' => [
        'longPrefix' => 'debug-info',
        'description' => 'Enable debug info',
        'required' => false,
    ],
];
