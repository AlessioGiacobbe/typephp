<?php

function compoundAdd(int $a, int $b): int
{
    $a += $b;
    return $a;
}

function compoundDiv(int $a, int $b): int
{
    $a /= $b;
    return $a;
}

function compoundMod(int $a, int $b): int
{
    $a %= $b;
    return $a;
}

function compoundShift(int $a, int $b): int
{
    $a <<= $b;
    return $a;
}

function postIncValue(int $a): int
{
    $old = $a++;
    return $old;
}

function preDecValue(int $a): int
{
    return --$a;
}

function bitwiseStaysRaw(int $a, int $b): int
{
    $a &= $b;
    return $a;
}
