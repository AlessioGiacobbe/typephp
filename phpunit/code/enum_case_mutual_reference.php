<?php

enum E: int
{
    case A = E::B;
    case B = E::A;
}

function main()
{
    var_dump(E::A);
}
