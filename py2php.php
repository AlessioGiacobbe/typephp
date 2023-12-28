<?php
$file = __DIR__ . '/cases/' . $_GET['file'];

echo shell_exec('php conv.php ' . $file . ' web');
