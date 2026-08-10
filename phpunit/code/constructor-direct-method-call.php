<?php
class ConstructorDirectMethodCall
{
    public function __construct() {}
}

function invoke_constructor(ConstructorDirectMethodCall $object): void
{
    $object->__construct();
}
