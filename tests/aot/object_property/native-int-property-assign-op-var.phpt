--TEST--
Native int property compound assignment from var
--FILE--
<?php
class NativeIntAssignOpBox
{
    public int $value = 1;

    public function add($delta): void
    {
        $this->value += $delta;
    }
}

function main(): void
{
    $box = new NativeIntAssignOpBox();
    $delta = any(2);
    $box->value += $delta;
    var_dump($box->value);

    $text = any("3");
    $box->value += $text;
    var_dump($box->value);

    $bad = any("abc");
    try {
        $box->value += $bad;
    } catch (TypeError $e) {
        var_dump($e->getMessage());
    }

    $selfBox = new NativeIntAssignOpBox();
    $selfDelta = any(5);
    $selfBox->add($selfDelta);
    var_dump($selfBox->value);
}
?>
--EXPECT--
int(3)
int(6)
string(39) "Unsupported operand types: int + string"
int(6)
