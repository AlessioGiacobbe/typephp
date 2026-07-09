--TEST--
Native int property assignment from string var uses setProperty fallback
--FILE--
<?php
class NativeIntStringVarBox
{
    public int $value = 0;
}

function main(): void
{
    $box = new NativeIntStringVarBox();

    $numeric = "123";
    $box->value = $numeric;
    var_dump($box->value);

    $bad = "abc";
    try {
        $box->value = $bad;
    } catch (TypeError $e) {
        var_dump($e->getMessage());
    }
}
?>
--EXPECT--
int(123)
string(74) "Cannot assign string to property NativeIntStringVarBox::$value of type int"
