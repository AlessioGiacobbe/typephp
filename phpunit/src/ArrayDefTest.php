<?php

final class ArrayDefTest extends \BaseTest
{
    public function testArrayDefDeclarationAndDirectWriteDiagnostics(): void
    {
        $this->exec('ArrayDef can only be applied to properties declared as array', 'array-def-non-array-property.php');
        $this->exec('ArrayDef expects one or two type arguments', 'array-def-no-arguments.php');
        $this->exec('ArrayDef expects one or two type arguments', 'array-def-too-many-arguments.php');
        $this->exec('ArrayDef map keys must use Type::Int or Type::String', 'array-def-invalid-map-key.php');
        $this->exec('ArrayDef map keys must use Type::Int or Type::String', 'array-def-class-map-key.php');
        $this->exec('ArrayDef map properties do not support append writes', 'array-def-map-append.php');
        $this->exec('expects key of type int, string given', 'array-def-static-key-mismatch.php');
        $this->exec('expects value of type string, int given', 'array-def-static-value-mismatch.php');
        $this->exec('expects value of type ArrayDefExpectedUser, ArrayDefOtherUser given', 'array-def-static-class-mismatch.php');
        $this->exec('Native class types cannot be used in ArrayDef', 'array-def-native-class-value.php');
        $this->exec('Std Container values cannot be stored in ArrayDef properties', 'array-def-std-container-value.php');
    }
}
