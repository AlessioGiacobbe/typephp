#include <phpx.h>
#include "phpx_func.h"

using namespace php;

Int php_fn_test(Int a, Int b) {
    auto c = a + b;
    var_dump(c);
    var_dump(php_uname());
    return c;
}
