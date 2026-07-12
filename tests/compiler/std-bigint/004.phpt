--TEST--
std bigint: foreach
--FILE--
<?php

function main() {
    $v = std::vector(native_types::type_bigint);
    $v[] = 10;
    $v[] = 20;
    $v[] = 30;

    $sum = 0;
    foreach ($v as $k => $val) {
        $sum = $sum + $val->toInt();
    }
    var_dump($sum);
}
?>
--EXPECT--
int(60)
