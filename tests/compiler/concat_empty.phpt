--TEST--
Concat empty strings
--FILE--
<?php
function main() {
    var_dump('' . 1 . '' . 'a' . '');
    var_dump('' . 1);
}
?>
--EXPECT--
string(2) "1a"
string(1) "1"
