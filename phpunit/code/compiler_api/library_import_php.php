<?php

namespace LibraryApi;

class Counter
{
    public const int STEP = 2;
    public int $value = 1;
    public int $doubled {
        get {
            return $this->value * 2;
        }
        set(int $value) {
            $this->value = intdiv($value, 2);
        }
    }

    public function add(int $amount = self::STEP): int
    {
        $this->value += $amount;
        return $this->value;
    }
}

function twice(int $value): int
{
    return $value * 2;
}
