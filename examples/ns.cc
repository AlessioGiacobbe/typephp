#include <phpx.h>
#include <phpx_func.h>
#include <php_func_decl.h>

using namespace php;

Int php_test(Int a, Var b, Str c, Array d) {
    php_var_dump(d, 10);
    return 0;
}