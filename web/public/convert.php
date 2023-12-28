<?php
if (empty($_POST['code'])) {
    die('require code');
}

$file = tempnam('/tmp', 'py2php');
file_put_contents($file, $_POST['code']);
$conv = dirname(__DIR__, 2) . '/conv.php';

$cmd = 'php ' . $conv . ' ' . $file;
$out = shell_exec($cmd);

echo json_encode([
    'data' => ['code' => $out, 'cmd' => $cmd]
]);

unlink($file);
