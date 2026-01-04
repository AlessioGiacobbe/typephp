<?php


function main() {
    $newArray = [];
    $newArray[] = 1;
    $newArray[] += [1]; // This is different: it's adding to index 1
    var_dump($newArray); // [1, 1]
}