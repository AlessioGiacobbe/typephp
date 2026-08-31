<?php
interface Ia {}
interface Ib {}
class TypeOk { public int|string $u; public function m(int|false $a, iterable $c, (Ia&Ib)|string $d): static { return $this; } }

function main() {}
