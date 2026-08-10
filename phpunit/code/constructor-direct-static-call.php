<?php
class ConstructorDirectStaticCall
{
    public function __construct() {}

    public function invoke(): void
    {
        self::__construct();
    }
}
