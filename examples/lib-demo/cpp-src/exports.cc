#include <php_typephp_lib_demo_func_decl.h>

#ifdef _WIN32
#define TYPEPHP_API extern "C" __declspec(dllexport)
#else
#define TYPEPHP_API extern "C" __attribute__((visibility("default")))
#endif

extern "C" int php_aot_runtime_init(int argc, char **argv);

TYPEPHP_API int typephp_lib_demo_add(int a, int b)
{
    char app_name[] = "typephp_lib_demo";
    char *argv[] = {app_name, nullptr};
    if (php_aot_runtime_init(1, argv) != 0) {
        return 0;
    }
    return static_cast<int>(php_demo_add(a, b));
}
