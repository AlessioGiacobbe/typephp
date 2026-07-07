--TEST--
Class implements interface: union type / composite type parameter compatibility
Tests parameter type contravariance for interface implementation with union types:
 - Child method omitting the type should accept a wider set of inputs.
 - Child method declaring the identical union type should also compile.
 - nullable union (T1|T2|null) and three-way union (T1|T2|T3) are covered.
--FILE--
<?php

// -------------------------------------------------------
// Interface with single + union type parameters
// -------------------------------------------------------
interface ContractA
{
    public function single(string $value);
    public function union2(string|int $value);
    public function union3(string|int|float $value);
    public function nullableUnion(string|int|null $value);
}

// Child omits every parameter type — must be compatible with all.
class ImplOmitAll implements ContractA
{
    public function single($value)  { var_dump($value); }
    public function union2($value)  { var_dump($value); }
    public function union3($value)  { var_dump($value); }
    public function nullableUnion($value) { var_dump($value); }
}

// -------------------------------------------------------
// Interface where child mirrors the union type exactly
// -------------------------------------------------------
interface ContractB
{
    public function mirror(string|int $x);
}

class ImplMirror implements ContractB
{
    public function mirror(string|int $x)
    {
        var_dump($x);
    }
}

// -------------------------------------------------------
// Multiple interfaces with different union types
// -------------------------------------------------------
interface ContractC
{
    public function a(string|bool $v);
}
interface ContractD
{
    public function b(int|float $v);
}

class ImplMulti implements ContractC, ContractD
{
    public function a($v) { var_dump($v); }
    public function b($v) { var_dump($v); }
}

function main()
{
    // ImplOmitAll — omitted types should still pass runtime checks
    $a = new ImplOmitAll;
    $a->single('hello');
    $a->union2('world');
    $a->union2(42);
    $a->union3(1);
    $a->union3('two');
    $a->union3(3.14);
    $a->nullableUnion(100);
    $a->nullableUnion(null);

    // ImplMirror — explicit matching union type
    $b = new ImplMirror;
    $b->mirror('ok');
    $b->mirror(99);

    // ImplMulti — multiple interfaces
    $c = new ImplMulti;
    $c->a('yes');
    $c->a(true);
    $c->b(123);
    $c->b(4.56);
}
?>
--EXPECT--
string(5) "hello"
string(5) "world"
int(42)
int(1)
string(3) "two"
float(3.14)
int(100)
NULL
string(2) "ok"
int(99)
string(3) "yes"
bool(true)
int(123)
float(4.56)
