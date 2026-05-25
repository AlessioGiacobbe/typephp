<?php
use native_types;

function main() {



// Int methods
$a = 100;
$a->add(50);
var_dump($a);

$a->sub(30);
var_dump($a);

$a->mul(2);
var_dump($a);

$a->div(4);
var_dump($a);

$b = $a->add(10);
var_dump($b);
var_dump($a); // $a also mutated

// Int conversion
var_dump($a->toString());
var_dump($a->toFloat());

// String methods
$s = "hello world";
var_dump($s->length());
var_dump($s->upper());
var_dump($s->substr(0, 5));
var_dump($s->split(" ")->count());

// Array methods
$arr = [];
$arr->append(100);
$arr->append(200);
$arr->append(300);
var_dump($arr->count());
var_dump($arr->get(0));
var_dump($arr->get(1));
var_dump($arr->exists(0));
var_dump($arr->empty());
var_dump($arr->isList());

// Array pop
$last = $arr->pop();
var_dump($last);
var_dump($arr->count());

// Array clean
$arr->clean();
var_dump($arr->empty());

// Float methods
$f = 3.14;
$f->add(1.0);
var_dump($f);
var_dump($f->toInt());

echo "done\n";

}
