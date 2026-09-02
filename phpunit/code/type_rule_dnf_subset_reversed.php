<?php
interface A {} interface B {} function f(A|(A&B) $x): void {}

function main() {}
