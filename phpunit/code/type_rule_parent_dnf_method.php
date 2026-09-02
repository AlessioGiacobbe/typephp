<?php
interface Ix {} class Other {} class B {} class C extends B { public function m((parent&Ix)|Other $x): void {} }

function main() {}
