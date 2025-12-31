#include "sapi/embed/php_embed.h"
#include <phpx.h>

void php_main();

extern php::Var argc;
extern php::Var argv;

int main(int cpp_argc, char **cpp_argv) {
    php_embed_init(cpp_argc, cpp_argv);
    int rc = 0;
    zend_first_try {
        argc = php::global("argc");
        argv = php::global("argv");
        php_main();
    }
    zend_catch {
        rc = EG(exit_status);
    }
    zend_end_try();

    argc.unset();
    argv.unset();
    php_embed_shutdown();
    return rc;
}
