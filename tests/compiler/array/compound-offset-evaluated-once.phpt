--TEST--
Array compound assignment evaluates a side-effecting offset once
--FILE--
<?php
declare(strict_types=1);

function main(): void
{
    $statementIndex = 0;
    $statementValues = [10];
    $statementValues[$statementIndex++] += 5;

    var_dump($statementIndex, $statementValues);

    $resultIndex = 0;
    $resultValues = [20];
    $result = ($resultValues[$resultIndex++] *= 2);

    var_dump($resultIndex, $resultValues, $result);

    $globalIndex = 0;
    $GLOBALS['typephp_compound_once_0'] = 3;
    $GLOBALS['typephp_compound_once_' . $globalIndex++] += 4;

    var_dump($globalIndex, $GLOBALS['typephp_compound_once_0']);
}
?>
--EXPECT--
int(1)
array(1) {
  [0]=>
  int(15)
}
int(1)
array(1) {
  [0]=>
  int(40)
}
int(40)
int(1)
int(7)
