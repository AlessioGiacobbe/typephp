#include "sapi/embed/php_embed.h"
#if PPROF_ON
#include <gperftools/profiler.h>
#endif
#include <phpx.h>

extern php::Var argc;
extern php::Var argv;

extern void php_app_init();
extern void php_app_clean();
extern zend_module_entry *php_embed_get_module();

static void throw_exception(zend_object *ex) {
    zend_bailout();
}

int main(int cpp_argc, char **cpp_argv) {
    php_embed_init(cpp_argc, cpp_argv);
    zend_throw_exception_hook = throw_exception;
    zend_module_entry *module = php_embed_get_module();

	if (zend_register_module_ex(module, MODULE_PERSISTENT) == NULL) {
        zend_error(E_ERROR, "Failed to register module [%s]", module->name);
	}

	if (zend_startup_module_ex(module) == FAILURE) {
        zend_error(E_ERROR, "Failed to startup module [%s]", module->name);
	}

    int rc = 0;
#if PPROF_ON
    ProfilerStart("profile.out");
#endif
    zend_first_try {
        php_app_init();
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
    php_app_clean();
    php::request_shutdown();
    php_embed_shutdown();

    return rc;
}
