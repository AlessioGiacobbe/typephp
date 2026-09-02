<?php
trait Builder { public function __construct() {} }
enum Suit { use Builder; case Hearts; }

function main() {}
