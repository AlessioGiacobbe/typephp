--TEST--
objval with parent::class
--FILE--
<?php

class Base {
    public function name(): string {
        return 'Base';
    }
}

class Child extends Base {
    public function name(): string {
        return 'Child';
    }

    public function castToParent($obj): Base {
        return objval($obj, parent::class);
    }
}

function main() {
    $b = new Base();
    $c = new Child();

    $result = $c->castToParent($b);
    var_dump($result->name());
}

?>
--EXPECT--
string(4) "Base"
