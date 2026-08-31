--TEST--
Enum cases stored in class constants keep their case identity
--FILE--
<?php
declare(strict_types=1);

enum E: int
{
    case A = 1;
    case B = 4;
}

enum P
{
    case X;
}

enum F: int
{
    case C = 1 + 1;
}

class K
{
    const CB = E::B;
    const PX = P::X;
    const FC = F::C;
}

function main(): void
{
    var_dump(K::CB === E::B);
    var_dump(K::PX === P::X);
    var_dump(K::FC === F::C);
    var_dump(F::C->value);
    var_dump(constant('K::CB') === E::B);
    $cls = 'K';
    var_dump($cls::CB === E::B);
    var_dump(K::CB instanceof E);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
int(2)
bool(true)
bool(true)
bool(true)
