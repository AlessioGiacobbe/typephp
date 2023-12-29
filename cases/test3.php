<?php
$operator = PyCore::import("operator");
$builtins = PyCore::import("builtins");

function invert_dictionary($obj) {
    return (function() {
        $___ = [];
                $___iter = $obj->items();
        foreach($___iter as $___i => [$key, $value]) {
            $___[] = [$key, $value];
        }
        return $___;
    })();
}


$ages = new PyDict([
    "Peter" => 10,
    "Isabel" => 11,
    "Anna" => 9,
]);
invert_dictionary($ages);
