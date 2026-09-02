<?php
interface A {} interface B {} function f((A&B)|A $x): void {}

function main() {}
