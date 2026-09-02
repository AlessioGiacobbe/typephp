<?php
interface A {} interface B {} interface C2 {} function f((A&B)|(A&B&C2) $x): void {}

function main() {}
