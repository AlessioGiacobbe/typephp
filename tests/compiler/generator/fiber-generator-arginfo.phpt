--TEST--
FiberGenerator methods expose Iterator-compatible return types
--INI--
error_reporting=E_ALL
--FILE--
<?php
function reflected_generator(): iterable
{
    yield 1;
}

function main(): void
{
    $generator = reflected_generator();
    foreach ([
        'rewind' => 'void',
        'next' => 'void',
        'valid' => 'bool',
        'current' => 'mixed',
        'key' => 'mixed',
        'send' => 'mixed',
        'throw' => 'mixed',
        'getReturn' => 'mixed',
    ] as $method => $expected) {
        $type = (new ReflectionMethod($generator, $method))->getReturnType();
        echo $method, ':', $type?->getName() ?? 'none', "\n";
    }
}
?>
--EXPECT--
rewind:void
next:void
valid:bool
current:mixed
key:mixed
send:mixed
throw:mixed
getReturn:mixed
