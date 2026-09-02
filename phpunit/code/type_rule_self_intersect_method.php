<?php
interface Ix {} class C { public function m(self&Ix $x): void {} }

function main() {}
