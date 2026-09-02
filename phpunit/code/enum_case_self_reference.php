<?php

enum E: int
{
    case A = E::A;
}

function main()
{
    var_dump(E::A);
}
