<?php
interface A {} interface B {} function f(object|(A&B) $x): void {}

function main() {}
