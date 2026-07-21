<?php

namespace LibraryApi;

use \ExtensionProvider as Provider;
use \NoExport as Internal;
use \Type;

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

    #[Internal]
    public function reset(): void
    {
        $this->value = 0;
    }
}

#[Internal]
class InternalCounter
{
    public function value(): int
    {
        return 42;
    }
}

#[Internal]
#[Provider(Type::String)]
class InternalStringExtension
{
    public static function byteLength(string $value): int
    {
        return strlen($value);
    }
}

function twice(int $value): int
{
    return $value * 2;
}

#[Internal]
function internal_twice(int $value = 2): int
{
    return $value * 2;
}
