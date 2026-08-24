--TEST--
PHP 8.5 clone-with respects property scope and unlocks readonly properties
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80500) {
    die('skip requires PHP 8.5');
}
?>
--XFAIL--
TypePHP internal classes do not yet preserve private/protected/readonly property scope during clone-with
--FILE--
<?php

class CloneWithScopeBase
{
    private int $privateValue = 1;
    protected int $protectedValue = 2;
    public readonly int $readonlyValue;

    public function __construct()
    {
        $this->readonlyValue = 3;
    }

    public function withPrivateAndReadonly(): self
    {
        return clone($this, [
            'privateValue' => 10,
            'readonlyValue' => 30,
        ]);
    }

    public function values(): array
    {
        return [$this->privateValue, $this->protectedValue, $this->readonlyValue];
    }
}

class CloneWithScopeChild extends CloneWithScopeBase
{
    public function withProtected(): self
    {
        return clone($this, ['protectedValue' => 20]);
    }
}

function main(): void
{
    $source = new CloneWithScopeChild();
    $privateCopy = $source->withPrivateAndReadonly();
    $protectedCopy = $source->withProtected();

    var_dump($source->values());
    var_dump($privateCopy->values());
    var_dump($protectedCopy->values());

    try {
        clone($source, ['protectedValue' => 99]);
    } catch (Error $error) {
        echo $error->getMessage(), "\n";
    }

    try {
        clone($source, ['readonlyValue' => 99]);
    } catch (Error $error) {
        echo $error->getMessage(), "\n";
    }
}
?>
--EXPECT--
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(2)
  [2]=>
  int(3)
}
array(3) {
  [0]=>
  int(10)
  [1]=>
  int(2)
  [2]=>
  int(30)
}
array(3) {
  [0]=>
  int(1)
  [1]=>
  int(20)
  [2]=>
  int(3)
}
Cannot access protected property CloneWithScopeChild::$protectedValue
Cannot modify protected(set) readonly property CloneWithScopeBase::$readonlyValue from global scope
