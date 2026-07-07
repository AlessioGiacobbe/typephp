<?php

#[Attribute(Attribute::TARGET_CLASS)]
final class PreprocessorAttributeArrayArgument
{
    public function __construct(public array $methods = [])
    {
    }
}

#[PreprocessorAttributeArrayArgument(methods: ['GET', 'POST'])]
class PreprocessorAttributeArrayArgumentController
{
}
