<?php

class std
{
    static function int(int $value): int
    {
        return $value;
    }

    static function float(float $value):  float
    {
        return $value;
    }

    static function bool(bool $value): bool
    {
        return $value;
    }

    static function string(string $str): resource
    {
        return fopen('php://stdin', 'r');
    }
}

function main()
{
    $a = std::int(100);
    var_dump($a);

    $b = std::float(100.0);
    var_dump($b);

    $c = std::bool(true);
    var_dump($c);
//    $b = true;
//    var_dump($b && $a = 99);
}
