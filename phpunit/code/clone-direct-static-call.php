<?php

class CloneDirectStaticCall
{
    public function __clone(): void
    {
    }

    public function invoke(): void
    {
        self::__clone();
    }
}
