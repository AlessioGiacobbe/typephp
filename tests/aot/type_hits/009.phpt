--TEST--
type hits: instance property type check message includes class name
--FILE--
<?php
function dynamic_value(mixed $value): mixed { return $value; }

class TypeHitPropertyMessage
{
    public int|string $union;

    public function setInvalid(): void
    {
        try {
            $this->union = dynamic_value(null);
        } catch (TypeError $e) {
            var_dump($e->getMessage());
        }
    }
}

function main()
{
    (new TypeHitPropertyMessage())->setInvalid();
}
?>
--EXPECT--
string(69) "TypeHitPropertyMessage::$union must be of type int|string, null given"
