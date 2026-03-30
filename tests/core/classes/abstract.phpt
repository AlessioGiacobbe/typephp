--TEST--
ZE2 An abstract method may not be called
--FILE--
<?php

abstract class fail {
    abstract function show();
}

class pass extends fail {
    function show() {
        echo "Call to function show()\n";
    }
    function error() {
        parent::show();
    }
}

function main() {
    $t = new pass();
    $t->show();
    $t->error();

    echo "Done\n"; // shouldn't be displayed
}
?>
--EXPECTF--
Call to function show()

Fatal error: Uncaught Error: Cannot call abstract method fail::show() in %s
Stack trace:
#0 Unknown(0) : %s
#1 {main}
  thrown in Unknown(0) : %s
