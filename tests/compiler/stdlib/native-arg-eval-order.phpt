--TEST--
Native optimized calls evaluate arguments left-to-right
--FILE--
<?php
var_dump(str_repeat((print "repeat-left\n") ? "x" : "x", (print "repeat-right\n") + 1));
var_dump(round((print "round-left\n") + 1.25, (print "round-right\n")));
var_dump(strcmp((print "cmp-left\n") ? "a" : "a", (print "cmp-right\n") ? "b" : "b"));
$text = '<x>';
var_dump($text->replace((print "method-left\n") ? "x" : "x", (print "method-right\n") ? "y" : "y"));
?>
--EXPECT--
repeat-left
repeat-right
string(2) "xx"
round-left
round-right
float(2.3)
cmp-left
cmp-right
int(-1)
method-left
method-right
string(3) "<y>"
