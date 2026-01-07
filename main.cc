#include "sapi/embed/php_embed.h"
#if PPROF_ON
#include <gperftools/profiler.h>
#endif
#include <phpx.h>

void php_main();

extern php::Var argc;
extern php::Var argv;

extern void php_init_constant_vars();
extern void php_init_global_vars();
extern void php_unset_global_vars();

static void throw_exception(zend_object *ex) {
    zend_bailout();
}

int main(int cpp_argc, char **cpp_argv) {
    php_embed_init(cpp_argc, cpp_argv);

    zend_execute_data fake_execute_data;
    memset(&fake_execute_data, 0, sizeof(zend_execute_data));
    zend_function fake_func {};
    fake_func.type = ZEND_INTERNAL_FUNCTION;
    fake_execute_data.func = &fake_func;
    EG(current_execute_data) = &fake_execute_data;

    zend_throw_exception_hook = throw_exception;

    int rc = 0;
#if PPROF_ON
    ProfilerStart("myapp.prof");
#endif
    zend_first_try {
        php_init_global_vars();
        php_init_constant_vars();
        php_main();
    }
    zend_catch {
        rc = EG(exit_status);
        if (EG(exception)) {
            zend_exception_error(EG(exception), E_ERROR);
        }
    }
    zend_end_try();
#if PPROF_ON
    ProfilerStop();
#endif
    php_unset_global_vars();
    php::request_shutdown();
    php_embed_shutdown();
    return rc;
}
