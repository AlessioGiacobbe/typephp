<?php
enum Suit: string { const Wild = "w"; case Hearts = "h"; case Spades = "s"; public function label(): string { return $this->value; } public function __invoke(): string { return $this->label(); } }

function main() {}
