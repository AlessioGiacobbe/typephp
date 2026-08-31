<?php

declare(strict_types=1);

namespace {
    // lib mode owns an embedded module but must not execute the bin entrypoint.
    // This declaration must also be omitted from the published import stub.
    function main(): void
    {
        throw new RuntimeException('lib mode invoked bin main()');
    }
}

namespace TypePhpIntegration\Library {
    function add(int $left, int $right): int
    {
        return $left + $right;
    }

    final class Counter
    {
        public int $value = 0;

        public function add(int $delta): int
        {
            return $this->value += $delta;
        }
    }
}
