<?php

enum G: int
{
    case A = G::A->value + 1;
}

function main()
{
    var_dump(G::A);
}
