<?php
interface A {} interface B {} function f((A&B)|self $x): void {}

function main() {}
