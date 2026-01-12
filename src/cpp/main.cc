#include "sapi/embed/php_embed.h"
#if PPROF_ON
#include <gperftools/profiler.h>
#endif
#include <phpx.h>

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_void, 0, 0, IS_VOID, 0)
ZEND_END_ARG_INFO()

void php_main();

extern php::Var argc;
extern php::Var argv;

extern void php_init_global_vars();
extern void php_unset_global_vars();

static ZEND_FUNCTION(main) {
	php_main();
}

static const zend_function_entry ext_functions[] = {
	ZEND_FE(main, arginfo_void)
	ZEND_FE_END
};

static void throw_exception(zend_object *ex) {
    zend_bailout();
}

int main(int cpp_argc, char **cpp_argv) {
    php_embed_init(cpp_argc, cpp_argv);
    zend_throw_exception_hook = throw_exception;
	zend_register_functions(nullptr, ext_functions, nullptr, 0);

    int rc = 0;
#if PPROF_ON
    ProfilerStart("myapp.prof");
#endif
    zend_first_try {
        php_init_global_vars();
        php::eval("main();");
    }
    zend_catch {
        rc = EG(exit_status);
        if (EG(exception)) {
            zend_exception_error(EG(exception), E_ERROR);
            zend_clear_exception();
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
