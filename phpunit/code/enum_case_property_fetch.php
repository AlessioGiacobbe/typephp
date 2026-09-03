<?php

enum E: int
{
    case One = 1;
    case Two = E::One->value + 1;
}

enum S: string
{
    case A = 'a';
    case B = S::A->name . '!';
}

function main()
{
    var_dump(E::Two->value);
    var_dump(S::B->value);
}
