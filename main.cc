#include "sapi/embed/php_embed.h"
#include <phpx.h>

void php_main();

int main(int argc, char **argv) {
    php_embed_init(argc, argv);
    int rc = 0;
    zend_first_try {
        php_main();
    }
    zend_catch {
        rc = EG(exit_status);
    }
    zend_end_try();

    php_embed_shutdown();
    return rc;
}
