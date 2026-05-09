--TEST--
return abstract class
--SKIPIF--
<?php die("skip"); ?>
--FILE--
<?php
class Base {
    public function foo() {
        return 'base';
    }
}

class User extends Base {
    public function foo()
    {
        return 'user';
    }
}

class Bar {
    public function getUser() : Base {
        return new User();
    }
}

function main()
{
    $bar = new Bar();
    $user = $bar->getUser();
    var_dump($user->foo());
}
?>
--EXPECT--
string(3) "foo"