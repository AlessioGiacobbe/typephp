--TEST--
Class constants valued by enum cases keep case identity everywhere
--FILE--
<?php
enum E: int { case B = 4; case A = 1 + 1; }
enum P { case X; }
enum TypedCase { case A; }

class K {
    public const CB = E::B;
    public const CX = P::X;
    public const VALUE = true ? E::A : E::B;
    public const TypedCase CASE_VALUE = TypedCase::A;
    public const MODE = RoundingMode::HalfEven;
}

class Alias {
    public const REF = K::CB;
}

function main(): void
{
    // Static access
    var_dump(K::CB === E::B);
    var_dump(K::CX === P::X);
    var_dump(K::CASE_VALUE === TypedCase::A);
    var_dump(K::MODE === RoundingMode::HalfEven);
    // Expression-valued constant and constant chains
    var_dump(K::VALUE === E::A);
    var_dump(Alias::REF === E::B);
    // Dynamic access
    var_dump(constant('K::CB') === E::B);
    var_dump(constant('K::VALUE') === E::A);
    $cls = 'K';
    var_dump($cls::MODE === RoundingMode::HalfEven);
    // Reflection
    var_dump((new ReflectionClassConstant('K', 'CASE_VALUE'))->getValue() === TypedCase::A);
    var_dump((string) (new ReflectionClassConstant('K', 'CASE_VALUE'))->getType());
    // Expression-valued backed case keeps its computed backing value
    var_dump(E::A->value);
    var_dump(K::VALUE->value);
}
?>
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
bool(true)
string(9) "TypedCase"
int(2)
int(2)
