<?php
$operator = PyCore::import("operator");
$builtins = PyCore::import("builtins");

function varargs(...$xxx) {
    return $xxx;
}


varargs(1, 2, 3);
