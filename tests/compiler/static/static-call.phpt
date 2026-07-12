--TEST--
static calls
--FILE--
<?php
$cls = 'DateTime';
$method = 'createFromFormat';
$now = $cls::$method('U', time());
?>
--EXPECTF--
