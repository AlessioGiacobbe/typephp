<?php

class FunctionTest extends \BaseTest
{
    public function testReturnRef(): void
    {
        $this->compile('function-return-ref.php');
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

    public function testInternalCallUnknownNamedArgument()
    {
        $this->exec('Unknown named argument `foo`', 'internal-call-unknown-named-arg.php');
    }

    public function testInternalCallMissingRequiredNamedArgument()
    {
        $this->exec('Named argument `replace` is missing default value', 'internal-call-missing-required-named-arg.php');
    }

    public function testUnpackAfterNamedArgument()
    {
        $this->exec('Cannot use argument unpacking after named arguments', 'unpack-after-named-arg.php');
    }

    public function testNativeCallPositionalAfterUnpack()
    {
        $this->exec('Cannot use positional argument after argument unpacking', 'native-call-positional-after-unpack.php');
    }

    public function testDynamicCallPositionalAfterUnpack()
    {
        $this->exec('Cannot use positional argument after argument unpacking', 'dynamic-call-positional-after-unpack.php');
    }

    public function testNewPositionalAfterUnpack()
    {
        $this->exec('Cannot use positional argument after argument unpacking', 'new-positional-after-unpack.php');
    }

    public function testClosureReferenceParameter()
    {
        $this->exec('Closure cannot use reference parameter', 'closure-ref-param.php');
    }

    public function testVariadicReferenceParameter()
    {
        $this->exec('Variadic parameters cannot be passed by reference', 'variadic-ref-param.php');
    }

    public function testOptionalParameterBeforeRequiredParameter()
    {
        $this->exec('test(): optional parameter `$a` cannot be declared before required parameter `$c`', 'optional-before-required-param.php');
    }

    public function testMethodOptionalParameterBeforeRequiredParameter()
    {
        $this->exec('OptionalBeforeRequired::method(): optional parameter `$first` cannot be declared before required parameter `$second`', 'method-optional-before-required-param.php');
    }

    public function testInternalVoidFunctionCannotBeAssigned()
    {
        $this->compile('internal-void-function-assignment.php');
    }

    public function testReflectedInternalVoidFunctionCannotBeAssigned()
    {
        $this->compile('internal-void-function-usleep-assignment.php');
    }

    public function testFullyQualifiedInternalVoidFunctionCannotBeAssigned()
    {
        $this->compile('internal-void-function-fully-qualified-assignment.php');
    }

    public function testInternalVoidFunctionCannotBeUsedAsBinaryOperand()
    {
        $this->compile('internal-void-function-binary-operand.php');
    }

}
