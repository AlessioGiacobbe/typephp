<?php
interface A {}

interface B {}

function main(): void {
    $closure = function ((A&B)|(B&A) $value): void {};
}
