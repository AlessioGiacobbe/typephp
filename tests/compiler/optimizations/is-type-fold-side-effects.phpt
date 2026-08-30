--TEST--
Folded is_int/is_float/is_bool must keep evaluating side-effect arguments
--FILE--
<?php
function intSource(): int
{
    echo "int-called\n";
    return 42;
}

function floatSource(): float
{
    echo "float-called\n";
    return 1.5;
}

function boolSource(): bool
{
    echo "bool-called\n";
    return true;
}

function main(): void
{
    if (is_int(intSource())) {
        echo "is-int\n";
    }
    echo is_float(floatSource()) ? "is-float\n" : "not-float\n";
    $r = is_bool(boolSource());
    echo $r ? "is-bool\n" : "not-bool\n";

    // Plain variables still fold without extra evaluation.
    $n = 7;
    echo is_int($n) ? "var-int\n" : "var-not-int\n";
}
?>
--EXPECT--
int-called
is-int
float-called
is-float
bool-called
is-bool
var-int
