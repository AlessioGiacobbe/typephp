<?php
interface Ia {} interface Ib {} class B {}
class C extends B implements Ia, Ib
{
    public function m((Ia&Ib)|self $a, (Ia&Ib)|parent $b): (Ia&Ib)|static
    {
        return $this;
    }
}

function main() {}
