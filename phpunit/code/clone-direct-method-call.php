<?php

class CloneDirectMethodCall
{
    public function __clone(): void
    {
    }

    public function invoke(): void
    {
        $this->__clone();
    }
}
