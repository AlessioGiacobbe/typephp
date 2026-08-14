<?php

#[Native]
class InvalidNativeConversion
{
    public function toArray(): string
    {
        return '';
    }
}

function main(): void
{
    $value = new InvalidNativeConversion();
    $value->toArray();
}
