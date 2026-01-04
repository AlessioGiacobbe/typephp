<?php

use PhpAot\Core\Translator;

function abort($v)
{
    /**
     * @var $translator Translator
     */
    global $translator;
    $lang = $translator->getLang();
    if ($translator->mode == 'cli') {
        echo 'Error: Unsupported ' . $lang . ' Syntax,';
        echo ' Line: ' . $translator->getLine($v) . ', Type: ' . $translator->getType($v) . PHP_EOL;
        if (DEBUG) {
            debug_print_backtrace();
            var_dump($v);
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode($v, JSON_PRETTY_PRINT);
    }
    die;
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
    exit;
}