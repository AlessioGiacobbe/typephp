--TEST--
consts
--FILE--
<?php
const C_I = 11;
const C_F = 1.1;
const C_S = "str";
const C_B = true;
const C_N = null;
const C_A = [1,2,3];
const C_O = new stdClass();

const C_I2 = -C_I;
const C_F2 = C_F * 2;
const C_S2 = C_S . "hello";

function main() {
    var_dump(C_I);
    var_dump(C_F);
    var_dump(C_S);
    var_dump(C_B);
    var_dump(C_N);
    var_dump(C_A);
    var_dump(C_O);
    var_dump(C_I2);
    var_dump(C_F2);
    var_dump(C_S2);
}

?>
--EXPECTF--
int(11)
float(1.1)
string(3) "str"
bool(true)
NULL
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
object(stdClass)#%d (0) {
}
int(-11)
float(2.2)
string(8) "strhello"

