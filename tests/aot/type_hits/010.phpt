--TEST--
type hits: property coalesce assignment uses runtime type check
--ENV--
USE_ZEND_ALLOC=0
--FILE--
<?php
function dynamic_value(mixed $value): mixed { return $value; }

class TypeHitCoalesceProperty
{
    public int|string $union;

    public function run(): void
    {
        try {
            $this->union ??= dynamic_value(null);
        } catch (TypeError $e) {
            var_dump($e->getMessage());
        }

        $this->union = "ok";
        $this->union ??= dynamic_value(null);
        var_dump($this->union);
    }
}

function main(): void
{
    (new TypeHitCoalesceProperty())->run();
}
?>
--EXPECT--
string(70) "TypeHitCoalesceProperty::$union must be of type int|string, null given"
string(2) "ok"
