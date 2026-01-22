#!/usr/bin/env php
<?php
if (count($argv) < 2) {
    echo "Usage: $argv[0] elf\n";
    exit(1);
}
$elf = $argv[1];
shell_exec("pprof --pdf $elf profile.out > profile.pdf");
