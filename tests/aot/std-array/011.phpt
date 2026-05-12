--TEST--
std array: unset
--FILE--
<?php
function main() {
    $a = std::array(native_types::type_int, 30);
    $a[11] = 99;
    var_dump($a[11]);
    unset($a[11]);
    var_dump($a[11]);
}
?>
--EXPECT--
int(99)
int(0)