<?php
function main(): void {
    $closure = function (): static {
        throw new Exception('never bound');
    };
}
