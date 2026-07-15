--TEST--
interface method `self` return type resolves to the interface's fully-qualified name inside a named namespace
--FILE--
<?php

namespace App {
    interface Chainable
    {
        public function chain(): self;
    }

    // comment inside a named namespace block (Stmt_Nop)
    class Widget implements Chainable
    {
        public array $log = [];

        public function chain(): self
        {
            $this->log[] = 'chain';
            return $this;
        }
    }
}

namespace {
    function main()
    {
        $w = new \App\Widget();
        var_dump($w->chain()->chain() instanceof \App\Chainable);
        var_dump(count($w->log));
    }
}
?>
--EXPECT--
bool(true)
int(2)
