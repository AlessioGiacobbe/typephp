<?php
if ($argc < 2) {
    die("Usage: php conv.php [python-file]\n");
}

require __DIR__ . '/vendor/autoload.php';
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

