--TEST--
extends redis
--FILE--
<?php
class MyRedis extends \redis
{
    public function __construct(?array $options = null)
    {
        parent::__construct($options);
    }
}

function main()
{
    $o = new MyRedis;
    $o->connect('127.0.0.1', 6379);
    $uuid = uniqid();
    var_dump($o->set('key', $uuid));
    var_dump($o->get('key') === $uuid);
}
?>
--EXPECT--
bool(true)
bool(true)
