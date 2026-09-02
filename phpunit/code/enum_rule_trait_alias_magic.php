<?php
trait Builder { public function build(): void {} }
enum Suit { use Builder { build as __destruct; } case Hearts; }

function main() {}
