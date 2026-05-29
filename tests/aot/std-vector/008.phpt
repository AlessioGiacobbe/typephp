--TEST--
std vector: unsafe_cast class value type mismatch
--FILE--
<?php
class StdVectorUnsafeCastBase
{
}

class StdVectorUnsafeCastOther
{
}

function std_vector_unsafe_ptr_class_type_mismatch($source): void
{
    $vector = std::unsafe_cast(std::vector(StdVectorUnsafeCastOther::class), $source);
}

function main() {
    $vector = std::vector(StdVectorUnsafeCastBase::class);
    try {
        std_vector_unsafe_ptr_class_type_mismatch($vector);
    } catch (TypeError $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
std::unsafe_cast(): std container type mismatch
