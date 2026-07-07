--TEST--
try-catch-finally: finally block must execute when exception is re-thrown inside catch block. Verifies that finally can access the caught exception variable ($e) even when the catch block re-throws it.
--FILE--
<?php
function main() {
    try {
        $a = 1;
        throw new RuntimeException('test');
        return 0; // trigger bug
    } catch (\Throwable $e) {
        echo 'Caught exception: ',  $e->getMessage(), "\n";
        throw $e;
    } finally {
        var_dump($a);
        echo 'Finally exception: ',  $e->getMessage(), "\n";
    }
    var_dump('This should not be reached');
}
?>
--EXPECT--
Caught exception: test
int(1)
Finally exception: test

Fatal error: Uncaught RuntimeException: test in Unknown(0) : eval():1
Stack trace:
#0 Unknown(0) : eval()(1): main()
#1 {main}
  thrown in Unknown(0) : eval() on line 1
