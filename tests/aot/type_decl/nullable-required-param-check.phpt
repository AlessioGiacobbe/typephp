--TEST--
Nullable parameter without default is still required
--FILE--
<?php
function expect_nullable_int(?int $x): void
{
    var_dump($x);
}

function main(): void
{
    $fn = 'expect_nullable_int';
    try {
        $fn();
    } catch (\Throwable $e) {
        var_dump(get_class($e));
        var_dump($e->getMessage());
    }
}
?>
--EXPECT--
string(18) "ArgumentCountError"
string(84) "Too few arguments to function expect_nullable_int(), 0 passed and exactly 1 expected"
