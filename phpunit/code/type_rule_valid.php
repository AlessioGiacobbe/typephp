<?php
interface Ia {}
interface Ib {}
class TypeOk { public int|string $u; public function m(int|false $a, iterable $c, (Ia&Ib)|string $d): static { return $this; } }
class Other {}
function acceptsDistinctClasses(TypeOk|Other $x, (Ia&Ib)|Other $y, (Ia&Ib)|null $z): void {}

function main() {}
