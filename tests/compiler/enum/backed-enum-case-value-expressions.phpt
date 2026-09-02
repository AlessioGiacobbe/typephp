--TEST--
Backed enum case values from constant expressions (arithmetic and constant references)
--FILE--
<?php

const TWO = 2;

enum Number: int {
    case Two = 1 + 1;
    case Three = TWO + 1;
    case Four = TWO * TWO;
}

enum Prefix: string {
    case Greeting = 'hel' . 'lo';
}

function main() {
    var_dump(Number::Two->value);
    var_dump(Number::Three->value);
    var_dump(Number::Four->value);
    var_dump(Number::Two->name);
    var_dump(Prefix::Greeting->value);
    var_dump(Number::from(4) === Number::Four);
}
?>
--EXPECT--
int(2)
int(3)
int(4)
string(3) "Two"
string(5) "hello"
bool(true)
