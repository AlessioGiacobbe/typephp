<?php
$operator = PyCore::import("operator");
$builtins = PyCore::import("builtins");
/**  与之对应的是多行注释
    用三个双引号表示，这两段双引号当中的内容都会被视作是注释
 */

$values = new PyList([]);
$kv = new PyDict([
    "hello" => "world",
]);
$__value = 3;
$values->__setitem__(0, $__value);
$__value = 10;
$values->__setitem__(1, $__value);
$c = 1 + 1;
$d = 8 - 1;
$e = 10 * 2;
$f = 35 / 5;
$g = $operator->floordiv(5 , 3);
$h = $operator->floordiv(-5 , 3);
$j = $operator->floordiv(5.5 , 3);
$k = $operator->floordiv(-5 , 3);
$__value = 7 % 3;
$values->__setitem__(10, $__value);
$__value = $operator->pow(2 , 3);
$values->__setitem__(11, $__value);
$__value = 1 + 3 * 2;
$values->__setitem__(12, $__value);
$__value = 1 + 3 * 2;
$values->__setitem__(13, $__value);
$_ = true;
$_ = false;
$_ = !true;
$_ = !false;
$_ = true && false;
$_ = false || true;
$_ = true + true;
$_ = true * 8;
$_ = false - 5;
$_ = 0 == false;
$_ = 1 == true;
$_ = 2 == true;
$_ = -5 != false;
$_ = PyCore::bool(0);
$_ = PyCore::bool(4);
$_ = PyCore::bool(-6);
$_ = 0 && 2;
$_ = -5 || 0;
$_ = 1 == 1;
$_ = 2 == 1;
$_ = 1 != 1;
$_ = 2 != 1;
$_ = 1 < 10;
$_ = 1 > 10;
$_ = 2 <= 2;
$_ = 2 >= 2;
$_ = 1 < 2 && 2 < 3;
$_ = 2 < 3 && 3 < 2;
$_ = 1 < 2;
$_ = 2 < 3;
$a = new PyList([1, 2, 3, 4]);
$b = $a;
$_ = $b == $a;
$_ = $b == $a;
$_ = new PyList([1, 2, 3, 4]);
$_ = $b == $a;
$_ = $b == $a;
$_ = "This is a string.";
$_ = "This is also a string.";
$_ = "Hello " + "world!";
$_ = "Hello world!";
$_ = "This is a string"->__getitem__(0);
$_ = PyCore::len("This is a string");
$name = "Reiko";
$_ = "She said her name is " . $name . ".";
$_ = $name . " is " . PyCore::len($name) . " characters long.";
$_ = null;
$_ = "etc" == null;
$_ = null == null;
$_ = PyCore::bool(null);
$_ = PyCore::bool(0);
$_ = PyCore::bool("");
$_ = PyCore::bool(new PyList([]));
$_ = PyCore::bool(new PyDict([
]));
$_ = PyCore::bool([]);
PyCore::print("I'm Python. Nice to meet you!");
PyCore::print("Hello, World", end: "!");
$input_string_var = PyCore::input("Enter some data: ");
$some_var = 5;
$_ = 3 > 2 ? "yahoo!" : 2;

function test() {
    if (3 > 2) {
        return "yahoo";
    } else {
        return 2;
    }

}


$li = new PyList([]);
$other_li = new PyList([4, 5, 6]);
$li->append(1);
$li->append(2);
$li->append(4);
$li->append(3);
$li->pop();
$li->append(3);
$_ = $li->__getitem__(0);
$_ = $li->__getitem__(-1);
$_ = $li->__getitem__(4);
$_ = $li->__getitem__(PyCore::slice(1, 3, null));
$_ = $li->__getitem__(PyCore::slice(2, null, null));
$_ = $li->__getitem__(PyCore::slice(null, 3, null));
$_ = $li->__getitem__(PyCore::slice(null, null, 2));
$_ = $li->__getitem__(PyCore::slice(null, null, -1));
$li2 = $li->__getitem__(PyCore::slice(null, null, null));
$li->__delitem__(2);

$li->remove(2);
$li->remove(2);
$li->insert(1, 2);
$li->index(2);
$li->index(4);
$tup = [1, 2, 3];
$tup->__getitem__(0);
$__value = 3;
$tup->__setitem__(0, $__value);
PyCore::type(1);
PyCore::type([1]);
PyCore::type([]);
$_ = PyCore::len($tup);
$_ = $tup + [4, 5, 6];
$_ = $tup->__getitem__(PyCore::slice(null, 2, null));
$_ = $tup->__contains__(2);
[$a, $b, $c] = [1, 2, 3];
[$d, $e, $f] = [4, 5, 6];
[$e, $d] = [$d, $e];
$invalid_dict = new PyDict([
    1 => "123",
]);
$_ = $invalid_dict->__getitem__("one");
$_ = $invalid_dict->get("one");
$filled_dict = new PyDict([
    "one" => 1,
    "two" => 2,
    "three" => 3,
]);
$_ = PyCore::list($filled_dict->keys());
$_ = PyCore::list($filled_dict->keys());
$_ = PyCore::list($filled_dict->values());
$_ = PyCore::list($filled_dict->values());
$_ = $filled_dict->__contains__("one");
$_ = $filled_dict->__contains__(1);
$empty_set = PyCore::set();
$some_set = new PySet([1, 1, 2, 2, 3, 4]);
$other_set = new PySet([3, 4, 5, 6]);
$filled_set = new PySet([1, 2, 3]);
$_ = $operator->bitand($filled_set , $other_set);
$_ = $operator->bitor($filled_set , $other_set);
$_ = new PySet([1, 2, 3, 4]) - new PySet([2, 3, 5]);
$_ = $operator->bitxor(new PySet([1, 2, 3, 4]) , new PySet([2, 3, 5]));
$_ = new PySet([1, 2]) >= new PySet([1, 2, 3]);
$_ = new PySet([1, 2]) <= new PySet([1, 2, 3]);
if ($some_var > 10) {
    PyCore::print("some_var is totally bigger than 10.");
} else {
    if ($some_var < 10) {
        PyCore::print("some_var is smaller than 10.");
    } else {
        PyCore::print("some_var is indeed 10.");
    }

}

$__iter = PyCore::iter(new PyList(["dog", "cat", "mouse"]));
while($current = PyCore::next($__iter)) {
    $animal = $current;
    PyCore::print(PyCore::str("{} is a mammal")->format($animal));
}
$__iter = PyCore::iter(PyCore::range(4));
while($current = PyCore::next($__iter)) {
    $i = $current;
    PyCore::print($i);
}
$animals = new PyList(["dog", "cat", "mouse"]);
$__iter = PyCore::iter(PyCore::enumerate($animals));
while($current = PyCore::next($__iter)) {
    [$i, $value] = $current;
    PyCore::print($i, $value);
}
$x = 0;
while($x < 4) {
    PyCore::print($x);
    $x += 1;
}
try {
    throw $builtins->IndexError("This is an index error");
} catch(PyError $e) {
    if (PyCore::isinstance($e, $builtins->IndexError)) {
        throw $builtins->IndexError("This is an index error");
    } elseif (PyCore::isinstance($e, new PyTuple([$builtins->TypeError, $builtins->NameError]))) {
        throw $builtins->IndexError("This is an index error");
    } else {
        throw $e;
    }
} finally {
    PyCore::print("We can clean up resources here");
}
$f__object = PyCore::open("myfile.txt");
$f = $f__object->__enter__();
try {
    $__iter = PyCore::iter($f);
    while($current = PyCore::next($__iter)) {
        $line = $current;
        PyCore::print($line);    
    }
} finally {
    $f__object->__exit__();
}

$contents = new PyDict([
    "aa" => 12,
    "bb" => 21,
]);
$file__object = PyCore::open("myfile1.txt", "w+");
$file = $file__object->__enter__();
try {
    $file->write(PyCore::str($contents));
} finally {
    $file__object->__exit__();
}

$file__object = PyCore::open("myfile2.txt", "w+");
$file = $file__object->__enter__();
try {
    $file->write($json->dumps($contents));
} finally {
    $file__object->__exit__();
}

$file__object = PyCore::open("myfile1.txt", "r+");
$file = $file__object->__enter__();
try {
    $contents = $file->read();
} finally {
    $file__object->__exit__();
}

PyCore::print($contents);
$file__object = PyCore::open("myfile2.txt", "r+");
$file = $file__object->__enter__();
try {
    $contents = $json->load($file);
} finally {
    $file__object->__exit__();
}

PyCore::print($contents);
$filled_dict = new PyDict([
    "one" => 1,
    "two" => 2,
    "three" => 3,
]);
$our_iterable = $filled_dict->keys();
PyCore::print($our_iterable);
$__iter = PyCore::iter($our_iterable);
while($current = PyCore::next($__iter)) {
    $i = $current;
    PyCore::print($i);
}
$our_iterable->__getitem__(1);
$our_iterator = PyCore::iter($our_iterable);
PyCore::next($our_iterator);
PyCore::next($our_iterator);
PyCore::next($our_iterator);
PyCore::next($our_iterator);
$our_iterator = PyCore::iter($our_iterable);
$__iter = PyCore::iter($our_iterator);
while($current = PyCore::next($__iter)) {
    $i = $current;
    PyCore::print($i);
}
PyCore::list($our_iterable);
PyCore::list($our_iterator);

function add($x, $y) {
    PyCore::print(PyCore::str("x is {} and y is {}")->format($x, $y));
    return $x + $y;
}


add(5, 6);
add(y: 6, x: 5);

function varargs(...$args) {
    return $args;
}


varargs(1, 2, 3);
