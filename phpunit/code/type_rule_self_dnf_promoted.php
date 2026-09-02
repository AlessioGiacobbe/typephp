<?php
interface Ix {} class Other {} class C { public function __construct(public (self&Ix)|Other $p) {} }

function main() {}
