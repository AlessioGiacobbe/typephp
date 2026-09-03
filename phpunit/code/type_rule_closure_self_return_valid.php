<?php
function main(): void {
    $closure = function (self $value): self {
        throw new Exception('never bound');
    };
}
