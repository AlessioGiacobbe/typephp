#!/usr/bin/env php
<?php
if ($argc < 2) {
    echo "Usage: php mv.php <file>\n";
    exit(1);
}
$file = $argv[1];
if (!file_exists($file)) {
    echo "File not found: " . $file . "\n";
    exit(1);
}
$newFile = str_replace('-raw', '', $file);
$dir = dirname($newFile);
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}
rename($file, $newFile);
shell_exec('git add ' . $newFile);
