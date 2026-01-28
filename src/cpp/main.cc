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

int main(int cpp_argc, char **cpp_argv) {
    php_embed_init(cpp_argc, cpp_argv);
    php::throw_impl = [](zend_object *ex) { throw ex; };
    zend_module_entry *module = php_embed_get_module();

    if (zend_register_module_ex(module, MODULE_PERSISTENT) == NULL) {
        zend_error(E_ERROR, "Failed to register module [%s]", module->name);
        return 255;
    }

    if (zend_startup_module_ex(module) == FAILURE) {
        zend_error(E_ERROR, "Failed to startup module [%s]", module->name);
        return 255;
    }

    int rc = 0;
#if PPROF_ON
    ProfilerStart("profile.out");
#endif
    try {
        php::request_init();
        php_app_init();
        php::eval("main();");
    } catch (zend_object *e) {
        rc = EG(exit_status);
        zend_exception_error(e, E_ERROR);
    }
#if PPROF_ON
    ProfilerStop();
#endif
    php_app_clean();
    php::request_shutdown();

    /**
     * There is a bug in PHP's handling of internal strings. All interned strings are released in the request shutdown
     * function, but then released again in the php_embed_shutdown function, resulting in a use-after-free issue. These
     * must be manually removed from the module table to prevent double release.
     */
    auto name_len = strlen(module->name);
    auto lcname = zend_string_alloc(name_len, module->type == MODULE_PERSISTENT);
    zend_str_tolower_copy(ZSTR_VAL(lcname), module->name, name_len);
    zend_hash_del(&module_registry, lcname);

    php_embed_shutdown();

    return rc;
}
