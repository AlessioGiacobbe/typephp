#if PPROF_ON
#include <gperftools/profiler.h>
#endif

#include <php_aot_helper.h>
#include "sapi/embed/php_embed.h"
#include "ps_title.h"

extern zend_module_entry *php_embed_get_module();

void module_init(zend_module_entry *module) {
    if (zend_register_module_ex(module, MODULE_PERSISTENT) == NULL) {
        zend_error(E_ERROR, "Failed to register module [%s]", module->name);
        exit(255);
    }
    if (zend_startup_module_ex(module) == FAILURE) {
        zend_error(E_ERROR, "Failed to startup module [%s]", module->name);
        exit(255);
    }
}

const char *php_get_called_class(php::Object &this_) {
    auto ce = php_get_called_ce(this_);
    if (ce) {
        return ce->name->val;
    } else {
        return "";
    }
}

zend_class_entry *php_get_called_ce(php::Object &this_) {
    if (this_.isObject()) {
        return this_.ce();
    } else {
        return (zend_class_entry *) Z_PTR_P(this_.ptr());
    }
}

void module_shutdown(zend_module_entry *module) {
    /**
     * There is a bug in PHP's handling of internal strings. All interned strings are released in the request shutdown
     * function, but then released again in the php_embed_shutdown function, resulting in a use-after-free issue. These
     * must be manually removed from the module table to prevent double release.
     */
    auto name_len = strlen(module->name);
    auto lcname = zend_string_alloc(name_len, module->type == MODULE_PERSISTENT);
    zend_str_tolower_copy(ZSTR_VAL(lcname), module->name, name_len);
    zend_hash_del(&module_registry, lcname);
}

int main(int cpp_argc, char **cpp_argv) {
    php_embed_init(cpp_argc, cpp_argv);
    php::throw_impl = [](zend_object *ex) { throw ex; };

    zend_module_entry *module = php_embed_get_module();
    module_init(module);

    save_ps_args(cpp_argc, cpp_argv);

    int rc = 0;
#if PPROF_ON
    ProfilerStart("profile.out");
#endif
    try {
        char path_translated[] = "embed";
        SG(request_info).path_translated = path_translated;
        module->request_startup_func(module->type, module->module_number);
    } catch (zend_object *e) {
        rc = EG(exit_status);
        CG(unclean_shutdown) = 1;
        zend_exception_error(e, E_ERROR);
    }
#if PPROF_ON
    ProfilerStop();
#endif

    module->request_shutdown_func(module->type, module->module_number);
    module_shutdown(module);
    php_embed_shutdown();

    return rc;
}
