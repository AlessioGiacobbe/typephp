--TEST--
count() on an array literal keeps spreads, duplicate keys and element side effects
--FILE--
<?php
function bump(): int
{
    echo "bump\n";
    return 1;
}

function main()
{
    // Element expressions must still run.
    var_dump(count([bump(), bump()]));

    // A repeated key collapses onto the first one.
    var_dump(count(['a' => 1, 'a' => 2]));

    // A spread contributes a count only known at runtime.
    $rest = [1, 2, 3, 4, 5];
    var_dump(count([...$rest, 9]));

    // Side effects of the elements must be observable afterwards.
    $i = 0;
    var_dump(count([$i++, $i++]));
    var_dump($i);

    // Plain literals stay eligible for the compile-time fold.
    $a = 1;
    var_dump(count([1, 2, 3]));
    var_dump(count([[1, 2], [3]]));
    var_dump(count([$a, -2, true, null]));
    var_dump(count([]));
}
?>
--EXPECT--
bump
bump
int(2)
int(1)
int(6)
int(2)
int(2)
int(3)
int(2)
int(4)
int(0)
