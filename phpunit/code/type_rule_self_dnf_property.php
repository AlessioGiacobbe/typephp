<?php
interface Ix {} class Other {} class C { public (self&Ix)|Other $p; }

function main() {}
