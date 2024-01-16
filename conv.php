<?php
if ($argc < 2) {
    die("Usage: php conv.php [python-file]\n");
}

define('DEBUG', getenv('PY2PHP_DEBUG'));
define('STEP', getenv('PY2PHP_STEP'));

$py_script = __DIR__ . '/dump.py';
$result = shell_exec('python ' . $py_script . ' ' . $argv[1]);
$json = json_decode($result);
if (empty($json)) {
    die("error py code");
}
if ($json->_type != 'Module') {
    echo "invalid python module\n";
}

function debug($v)
{
    global $translator;
    if ($translator->mode == 'cli') {
        echo 'Error: Unsupported Python Syntax, Line: ' . $v->lineno . ', Type: ' . $v->_type . PHP_EOL;
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


error_reporting(E_ERROR);
// web or cli
if (!empty($argv[2])) {
    $mode = $argv[2];
} else {
    $mode = 'cli';
}

$translator = new PhpAot\Python\Translator();
$translator->setMode($mode);
$translator->setIndent('    ');
$translator->convert($json);

