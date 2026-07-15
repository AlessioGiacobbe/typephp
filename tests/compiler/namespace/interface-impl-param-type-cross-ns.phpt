--TEST--
Cross-namespace interface implementation with an interface-typed parameter must not be reported as incompatible
--FILE--
<?php
namespace A {
    use B\I1;
    use B\I2;

    abstract class Test implements I2
    {
        public function test(I1 $a): bool
        {
            return true;
        }
    }
}

namespace B {
    interface I1
    {
    }

    interface I2
    {
        public function test(I1 $a): bool;
    }

    class Impl1 implements I1
    {
    }
}

namespace {
    class Concrete extends \A\Test
    {
    }

    function main()
    {
        $obj = new Concrete();
        var_dump($obj->test(new \B\Impl1()));
        echo "done\n";
    }
}
?>
--EXPECT--
bool(true)
done
