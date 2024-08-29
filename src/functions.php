<?php

use PhpAot\Core\Translator;

function debug($v)
{
    /**
     * @var $translator Translator
     */
    global $encryptor;
    $lang = $encryptor->getLang();
    if ($encryptor->mode == 'cli') {
        echo 'Error: Unsupported ' . $lang . ' Syntax, Line: ' . $encryptor->getLine($v) . ', Type: ' . $encryptor->getType($v) . PHP_EOL;
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
        debug($v);
    }
}

function if_not_empty_debug($if_expr, $v)
{
    if (!empty($if_expr)) {
        debug($v);
    }
}
