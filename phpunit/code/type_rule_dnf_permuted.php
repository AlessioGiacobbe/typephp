<?php
interface A {} interface B {} function f((A&B)|(B&A) $x): void {}

function main() {}
