<?php

namespace App;

// One definition spelled two ways: Zend evaluates both initializers and the
// composed values match. The evaluation resolves `self::PARTS` against a
// fully qualified trait/class name; the current namespace must not be
// prepended a second time (`App\App\...`), and the comparison must not fall
// back to spelling equality (the spellings differ on purpose).
trait T1
{
    const PARTS = [1, 2];
    const ALL = [...self::PARTS, 9];
}

trait T2
{
    const PARTS = [1, 2];
    const ALL = [...self::PARTS, 8 + 1];
}

class C
{
    use T1;
    use T2;
}

function main(): void
{
    var_dump(C::ALL);
}
