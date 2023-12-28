<?php
if (empty($_POST['code'])) {
    die('require code');
}

define('ROOT_PATH', dirname(__DIR__, 2));

$id = date('Ymd_H_') . uniqid();

$file = ROOT_PATH . '/logs/' . $id . '.py';
$out_file = ROOT_PATH . '/logs/' . $id . '.php';

file_put_contents($file, $_POST['code']);
$conv = ROOT_PATH . '/conv.php';

$cmd = 'php ' . $conv . ' ' . $file;
$out = shell_exec($cmd);

echo json_encode([
    'data' => ['code' => $out,]
]);

file_put_contents($out_file, $out);
