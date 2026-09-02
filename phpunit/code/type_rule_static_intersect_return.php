<?php
interface Ix {} class C { public function m(): static&Ix { return $this; } }

function main() {}
