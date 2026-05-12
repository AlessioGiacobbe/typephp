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

function std_vector_unsafe_ptr_class_type_mismatch(UnsafePtr $unsafePtr): void
{
    $vector = std::unsafe_cast(std::vector(StdVectorUnsafeCastOther::class), $unsafePtr);
}

function main() {
    $vector = std::vector(StdVectorUnsafeCastBase::class);
    $ptr = std::unsafe_ptr($vector);

    try {
        std_vector_unsafe_ptr_class_type_mismatch($ptr);
    } catch (RuntimeException $e) {
        echo $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
std::unsafe_cast(): UnsafePtr type mismatch
