<?php
interface Ix {} class Other {} class C { public function m(): (static&Ix)|Other { return $this; } }

function main() {}
