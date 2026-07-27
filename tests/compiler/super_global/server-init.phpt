--TEST--
$_SERVER: init PHP_SELF, SCRIPT_NAME, SCRIPT_FILENAME, PATH_TRANSLATED and DOCUMENT_ROOT
--FILE--
<?php

function main()
{
    require __DIR__ . '/../../../src/Assert.php';
    Assert::true(isset($_SERVER['PHP_SELF']));
    Assert::true(isset($_SERVER['SCRIPT_NAME']));
    Assert::true(isset($_SERVER['SCRIPT_FILENAME']));
    Assert::true(isset($_SERVER['PATH_TRANSLATED']));
    Assert::true(isset($_SERVER['DOCUMENT_ROOT']));
    Assert::eq($_SERVER['DOCUMENT_ROOT'], '');
}
?>
--EXPECT--
