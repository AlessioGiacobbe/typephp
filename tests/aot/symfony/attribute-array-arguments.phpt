--TEST--
Symfony pattern: attribute array arguments
--SKIPIF--
<?php
exit('skip Array arguments to attributes are not supported by the AOT compiler');
?>
--FILE--
<?php

#[Attribute(Attribute::TARGET_CLASS)]
final class SymfonyLikeRoute
{
    public function __construct(public array $methods = [])
    {
    }
}

#[SymfonyLikeRoute(methods: ['GET', 'POST'])]
class SymfonyLikeController
{
}

function main(): void
{
    $route = (new ReflectionClass(SymfonyLikeController::class))->getAttributes(SymfonyLikeRoute::class)[0]->newInstance();
    var_dump($route->methods);
}
?>
--EXPECT--
array(2) {
  [0]=>
  string(3) "GET"
  [1]=>
  string(4) "POST"
}
