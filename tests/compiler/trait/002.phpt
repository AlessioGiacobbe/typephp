--TEST--
Single Trait with simple trait method
--FILE--
<?php
trait HelloTrait {
    public $name = 'world';
    public function hello() {
        echo 'Hello ' . $this->name . PHP_EOL;
    }
}

function main() {
    eval("(new class { use HelloTrait;})->hello();");
}
?>
--EXPECT--
Hello world
