<?php
interface Ix {} class Other {} class C { public function m((self&Ix)|Other $x): void {} }

function main() {}
