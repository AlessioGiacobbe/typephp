<?php

use PhpAot\Core\Translator;
use PhpAot\Php\Unsupported;

function abort($v)
{
    /**
     * @var $translator Translator
     */
    global $translator;
    $lang = $translator->getLang();
    $msg = 'Error: Unsupported ' . $lang . ' Syntax,';
    $msg .= ' Line: ' . $translator->getLine($v) . ', Type: ' . $translator->getType($v) . PHP_EOL;
    if ($translator->mode == 'cli') {
//        if (DEBUG) {
//            var_dump($v);
//        }
    } else {
        header('Content-Type: application/json');
        echo json_encode($v, JSON_PRETTY_PRINT);
    }
    throw new Unsupported($msg);
}

function if_empty_debug($if_expr, $v)
{
    if (empty($if_expr)) {
        abort($v);
    }
}

function if_not_empty_debug($if_expr, $v)
{
    if (!empty($if_expr)) {
        abort($v);
    }
}


function debug()
{
    foreach(func_get_args() as $arg) {
        var_dump($arg);
    }
    debug_print_backtrace();
    exit;
}