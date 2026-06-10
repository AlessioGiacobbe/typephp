<?php

class FunctionTest extends \BaseTest
{
    public function testReturnRef()
    {
        $this->exec('The return type of the function `test` cannot be a reference type', 'function-return-ref.php');
    }

    public function testNativeCallUnknownNamedArgument()
    {
        $this->exec('Unknown named argument `missing`', 'native-call-unknown-named-arg.php');
    }

    public function testNativeCallDuplicateNamedArgument()
    {
        $this->exec('Duplicate named argument `value`', 'native-call-duplicate-named-arg.php');
    }

    public function testNativeCallPositionalAfterNamedArgument()
    {
        $this->exec('Cannot use positional argument after named argument', 'native-call-positional-after-named.php');
    }

    public function testNativeCallNamedArgumentOverwritesPositionalArgument()
    {
        $this->exec('Named argument `value` overwrites previous argument', 'native-call-named-overwrites-positional.php');
    }

}
