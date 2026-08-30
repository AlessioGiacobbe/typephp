--TEST--
use const with a single-segment (global) constant name
--FILE--
<?php
namespace App {
    use const PHP_EOL;

    const ANSWER = 42;

    function hello(): string
    {
        return "hello" . PHP_EOL;
    }
}

namespace {
    use const App\ANSWER;

    function main(): void
    {
        echo \App\hello();
        echo ANSWER, "\n";
    }
}
?>
--EXPECT--
hello
42
