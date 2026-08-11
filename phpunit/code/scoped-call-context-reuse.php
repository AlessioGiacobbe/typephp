<?php

class ScopedCallContextReuse
{
    private function mapValue(int $value): int
    {
        return $value * 2;
    }

    public function run(array $rows): void
    {
        foreach ($rows as $row) {
            array_map([$this, 'mapValue'], $row);
            array_filter($row, [$this, 'mapValue']);
        }

        $callback = self::mapValue(...);
        $callback(1);
    }
}
