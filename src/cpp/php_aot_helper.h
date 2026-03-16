#include <phpx.h>
#include <phpx_helper.h>

#include <zend_attributes.h>

extern zend_class_entry *php_get_class(int class_id, const php::Str &class_name);
extern zend_function *php_get_func(int func_id, const php::Str &func_name);
extern zend_function *php_get_method(int func_id, const php::Str &method_name, int class_id, const php::Str &class_name);
extern uint32_t php_get_prop(int prop_id, const php::Str &prop_name, int class_id, const php::Str &class_name);

namespace php {
struct Scope {
	zend_class_entry *ce;
	zend_execute_data *frame;
};
};

extern const char *php_get_called_class(php::Object &this_);
extern zend_class_entry *php_get_called_ce(php::Object &this_);
extern php::Scope php_switch_scope(php::Object &this_);
extern void php_restore_scope(php::Scope &ori_scope);

static inline php::Variant CALL(int func_id, const php::Str &func_name) {
    return php::call(php_get_func(func_id, func_name));
}

static inline php::Variant CALL(int func_id, const php::Str &func_name, const php::ArgList &args) {
    return php::call(php_get_func(func_id, func_name), args);
}

static inline php::Variant CALL(int func_id, const php::Str &func_name, php::Array &args) {
    return php::call(php_get_func(func_id, func_name), args);
}

static inline php::Variant CALL_SILENT(int func_id, const php::Str &func_name) {
    return php::silentCall(php_get_func(func_id, func_name));
}

static inline php::Variant CALL_SILENT(int func_id, const php::Str &func_name, const php::ArgList &args) {
    return php::silentCall(php_get_func(func_id, func_name), args);
}

static inline php::Variant CALL_SILENT(int func_id, const php::Str &func_name, php::Array &args) {
    return php::call(php_get_func(func_id, func_name), args);
}
