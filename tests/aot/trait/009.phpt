--TEST--
Method conflict in traits
--FILE--
<?php
namespace App1 {
    class Foo1 {
        public string $prop = 'Hello 1';
    }
}

namespace App2 {
    use App1\Foo1;
    trait THello1
    {
        public function hello(Foo1 $foo1)
        {
            var_dump($foo1);
        }
    }
}

namespace App3 {
    use App2\THello1;
    class TraitsTest
    {
        use THello1;
    }
}

namespace {
    function main()
    {
        $foo1 = new App1\Foo1;
        $o = new App3\TraitsTest;
        $o->hello($foo1);
    }
}
?>
--EXPECT--