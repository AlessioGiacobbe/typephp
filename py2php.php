<?php
$file = __DIR__ . '/cases/' . $_GET['file'];

$op = empty($_GET['op']) ? 'conv' : $_GET['op'];

switch ($op) {
    case 'conv':
        echo shell_exec('php conv.php ' . $file . ' web');
        break;
    case 'dump':
        echo shell_exec('python dump.py ' . $file);
        break;
    default:
        break;
}


