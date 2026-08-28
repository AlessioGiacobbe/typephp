--TEST--
Trait composition: concrete fulfills abstract, alias keeps static, class method wins
--FILE--
<?php

// A concrete trait method fulfills an abstract requirement from another
// trait, regardless of the order the traits are listed in.
trait NeedsName { abstract public function name(): string; }
trait HasName { public function name(): string { return "HasName"; } }
class AbstractFirst { use NeedsName, HasName; }
class ConcreteFirst { use HasName, NeedsName; }

// An alias visibility change keeps the `static` flag.
trait Maker {
    public static function make(): string { return "made"; }
}
class Factory {
    use Maker { make as protected; }
    public static function build(): string { return static::make(); }
}

// An alias under a new name keeps the `static` flag too.
trait Counter {
    public static function count7(): int { return 7; }
}
class Stats {
    use Counter { count7 as protected seven; }
    public static function total(): int { return static::seven(); }
}

// The class's own method wins over two same-name trait methods without
// this counting as a trait-vs-trait conflict.
trait WhoA { public function who(): string { return "WhoA"; } }
trait WhoB { public function who(): string { return "WhoB"; } }
class Self1 { use WhoA, WhoB; public function who(): string { return "Self1"; } }

function main(): void
{
    echo (new AbstractFirst())->name(), "\n";
    echo (new ConcreteFirst())->name(), "\n";
    echo Factory::build(), "\n";
    echo Stats::total(), "\n";
    echo (new Self1())->who(), "\n";
}
?>
--EXPECT--
HasName
HasName
made
7
Self1
