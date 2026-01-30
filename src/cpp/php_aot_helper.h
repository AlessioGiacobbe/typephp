#include <phpx.h>

extern zend_class_entry *php_get_class(int class_id, const char *class_name);
extern zend_function *php_get_func(int func_id, const char *func_name);

static inline php::Variant CALL(int func_id, const char *func_name, const std::initializer_list<php::Variant> &args) {
    return php::call(php_get_func(func_id, func_name), args);
}

static inline php::Variant CALL(int func_id, const char *func_name) {
    return php::call(php_get_func(func_id, func_name));
}

static inline php::Variant CALL_SILENT(int func_id, const char *func_name, const std::initializer_list<php::Variant> &args) {
    return php::silentCall(php_get_func(func_id, func_name), args);
}

static inline php::Variant CALL_SILENT(int func_id, const char *func_name) {
    return php::silentCall(php_get_func(func_id, func_name));
}
