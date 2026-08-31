<?php

enum CaseConstEnum: int
{
    case A = 1 + 1;
    case B = 4;
}

interface CaseConstInterface
{
    const IC = CaseConstEnum::B;
}

class CaseConstHolder implements CaseConstInterface
{
    const CB = CaseConstEnum::B;
    const CHAIN = self::CB;
}

function main(): void
{
    var_dump(CaseConstHolder::CB === CaseConstEnum::B);
}
