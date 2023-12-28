<?php
if (empty($_POST['code'])) {
    die('require code');
}

define('ROOT_PATH', dirname(__DIR__, 2));

$id = date('Ymd_H_') . uniqid();

$py_file = ROOT_PATH . '/logs/' . $id . '.py';
$php_file = ROOT_PATH . '/logs/' . $id . '.php';

file_put_contents($py_file, $_POST['code']);
$conv = ROOT_PATH . '/conv.php';

$cmd = 'php ' . $conv . ' ' . $py_file;
$code = shell_exec($cmd);

echo json_encode([
    'data' => ['code' => $code,]
]);

file_put_contents($php_file, $code);

// 运行正常，删除历史文件
if (str_starts_with($code, '<?php')) {
    unlink($py_file);
    unlink($php_file);
}
