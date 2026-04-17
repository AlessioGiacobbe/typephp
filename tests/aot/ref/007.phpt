--TEST--
class const 001
--FILE--
<?php
class WorkerA
{
    protected function foo(string $name, string &$value) {
        $value = 'hello ' . $name;
    }
}

class WorkerB extends WorkerA
{
    public function bar(string $name) {
        $value = '';
        $this->foo($name, $value);
        return $value;
    }
}

function main()
{
    $o = new WorkerB;
    var_dump($o->bar('php'));
}
?>
--EXPECT--
string(9) "hello php"