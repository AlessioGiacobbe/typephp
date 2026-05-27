--TEST--
Break and continue with numeric levels
--SKIPIF--
<?php
echo "skip Break/continue with levels > 1 not supported in AOT";
?>
--FILE--
<?php
// break 2
for ($i = 0; $i < 3; $i++) {
    for ($j = 0; $j < 3; $j++) {
        if ($i == 1 && $j == 2) {
            break 2;
        }
    }
}
echo "done\n";
?>
--EXPECT--
done
