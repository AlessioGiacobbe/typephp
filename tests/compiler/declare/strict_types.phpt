--TEST--
declare: strict types 1
--FILE--
<?php
declare(strict_types=1);
function main() {
    parse_url(0);
}
?>
--EXPECTF--
Fatal error: Uncaught TypeError: parse_url(): Argument #1 ($url) must be of type string, int given in %s:%d
Stack trace:
#0 %s
#1 %s
#2 {main}
  thrown in %s on line %d

