--TEST--
std map: 001
--FILE--
<?php
function main() {
    $map = std::map(native_types::type_int, native_types::type_float);
    $map[10] = 1.25;
    $map[10] += 0.75;
    $map[11] = 3.5;

    var_dump($map[10] == 2.0);
    var_dump($map[11] == 3.5);
    var_dump(count($map));
}
?>
--EXPECT--
bool(true)
bool(true)
int(2)
