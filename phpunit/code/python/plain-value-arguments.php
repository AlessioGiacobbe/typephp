<?php

function invalidPlainValueCall(PyObject $value): void
{
    $value->toPlainValue(1);
}

function main(): void
{
}
