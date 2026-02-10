#include <phpx.h>
#include <phpx_helper.h>
#include <phpx_func.h>
#include <php_func_decl.h>

extern php::Var _GET;
extern php::Var _POST;
extern php::Var _COOKIE;
extern php::Var _SERVER;
extern php::Var _FILES;
extern php::Var _SESSION;
extern php::Var _REQUEST;
extern php::Var GLOBALS;
extern php::Var argc;
extern php::Var argv;
extern php::Var _literal_strings[0];

php::Bool php_write_mandelbrot_to_stream(php::Var w, php::Var h, php::Var stream, php::Var bitmap) {
    php::Int bit_num;
    php::Int byte_acc;
    php::Int iter;
    php::Float yfac;
    php::Float xfac;
    php::Str ochars;
    php::Str pack_format;
    php::Int y;
    php::Float Ci;
    php::Int x;
    php::Float Zr;
    php::Float Zi;
    php::Float Tr;
    php::Float Ti;
    php::Float Cr;
    php::Int i;
    php::Object tmp_var_0;
    php::Object e;

    // Stmt_If [15:16]
    if (bitmap) {
        // Stmt_Expression [16:16]
        php::call(php::fprintf, {stream, "P4\n%d %d\n", w, h});
    }

    // Stmt_Expression [18:18]
    bit_num = php::toInt(128L);
    // Stmt_Expression [19:19]
    byte_acc = php::toInt(0L);
    // Stmt_Expression [20:20]
    iter = php::toInt(50L);
    // Stmt_Expression [22:22]
    yfac = php::to_float(2.0 / (h));
    // Stmt_Expression [23:23]
    xfac = php::to_float(2.0 / (w));
    // Stmt_Expression [25:25]
    ochars = " .:-;!/>)|&IH%*#";
    // Stmt_Expression [26:26]
    pack_format = "c*";
    // Stmt_For [28:78]
    y = php::toInt(0L);
    for (; php::to_bool((y) < (php::to_int(h))); ++y) {
        // Stmt_Expression [29:29]
        Ci = php::to_float((((php::to_float(y)) * (yfac))) - (php::to_float(1.0)));
        // Stmt_For [30:68]
        x = php::toInt(0L);
        for (; php::to_bool((x) < (php::to_int(w))); ++x) {
            // Stmt_Expression [31:31]
            Zr = php::to_float(0.0);
            // Stmt_Expression [32:32]
            Zi = php::to_float(0.0);
            // Stmt_Expression [33:33]
            Tr = php::to_float(0.0);
            // Stmt_Expression [34:34]
            Ti = php::to_float(0.0);
            // Stmt_Expression [35:35]
            Cr = php::to_float((((php::to_float(x)) * (xfac))) - (php::to_float(1.5)));
            // Stmt_TryCatch [37:51]
            zend_try {
                // Stmt_Do [38:49]
                do {
                    // Stmt_For [39:47]
                    i = php::toInt(0L);
                    for (; php::to_bool((i) < (php::toInt(iter))); ++i) {
                        // Stmt_Expression [40:40]
                        Zi = php::to_float((((((2.0) * (php::to_float(Zr)))) * (php::to_float(Zi)))) +
                                           (php::to_float(Ci)));
                        // Stmt_Expression [41:41]
                        Zr = php::to_float((((Tr) - (php::to_float(Ti)))) + (php::to_float(Cr)));
                        // Stmt_Expression [42:42]
                        Tr = php::to_float((Zr) * (php::to_float(Zr)));
                        // Stmt_If [44:46]
                        if (php::to_bool((((Tr) + (php::to_float(Ti = php::to_float((Zi) * (php::to_float(Zi))))))) >
                                         (php::to_float(4.0)))) {
                            // Stmt_Expression [45:45]
                            php::throwException(php::newObject("Exception", "break 2"));
                        }
                    }

                    // Stmt_Expression [48:48]
                    byte_acc += php::toInt(bit_num);
                } while (false);
            }
            zend_catch {
                tmp_var_0 = php::catchException();
                e = tmp_var_0;
                if (e && php::instanceOf(e, "Exception")) {
                    tmp_var_0.unset();
                }
            }
            zend_end_try();
            if (tmp_var_0) {
                php::throwException(tmp_var_0);
            }
            // Stmt_If [53:67]
            if (bitmap) {
                // Stmt_If [54:60]
                if (php::same(bit_num, 1L)) {
                    // Stmt_Expression [55:55]
                    php::call(php::fwrite, {stream, php::call(php::pack, {pack_format, byte_acc})});
                    // Stmt_Expression [56:56]
                    bit_num = php::toInt(128L);
                    // Stmt_Expression [57:57]
                    byte_acc = php::toInt(0L);
                } else {
                    // Stmt_Expression [59:59]
                    bit_num >>= 1L;
                }

            } else {
                // Stmt_If [62:66]
                if (php::equals(i, iter)) {
                    // Stmt_Expression [63:63]
                    php::call(php::fwrite, {stream, ochars.offsetGet(0L)});
                } else {
                    // Stmt_Expression [65:65]
                    php::call(php::fwrite,
                              {stream, ochars.offsetGet((((i) + (php::toInt(1L)))) & (php::toInt(15L)))});
                }
            }
        }

        // Stmt_If [69:77]
        if (bitmap) {
            // Stmt_If [70:74]
            if (!(php::same(bit_num, 128L))) {
                // Stmt_Expression [71:71]
                php::call(php::fwrite, {stream, php::call(php::pack, {pack_format, byte_acc})});
                // Stmt_Expression [72:72]
                bit_num = php::toInt(128L);
                // Stmt_Expression [73:73]
                byte_acc = php::toInt(0L);
            }

        } else {
            // Stmt_Expression [76:76]
            php::call(php::fwrite, {stream, "\n"});
        }
    }

    // Stmt_Return [79:79]
    return php::to_bool(true);
}

php::Bool php_treffynnon_mandelbrot_to_file(php::Var filename, php::Var w, php::Var h, php::Var binary_output) {
    php::Str file_open_type;
    php::Var stream;

    // Stmt_Expression [84:84]
    file_open_type = "w";
    // Stmt_If [85:86]
    if (binary_output) {
        // Stmt_Expression [86:86]
        file_open_type = "wb";
    }

    // Stmt_Expression [88:88]
    stream = php::call(php::fopen, {php::to_string(filename), file_open_type});
    // Stmt_If [89:90]
    if (php::same(false, stream)) {
        // Stmt_Return [90:90]
        return php::to_bool(false);
    }

    // Stmt_Expression [92:92]
    php_write_mandelbrot_to_stream(php::to_int(w), php::to_int(h), stream, php::to_bool(binary_output));
    // Stmt_Expression [93:93]
    php::call(php::fclose, {stream});
    // Stmt_Return [94:94]
    return php::to_bool(true);
}

php::Str php_treffynnon_mandelbrot_to_mem(php::Var w, php::Var h, php::Var binary_output) {
    php::Str file_open_type;
    php::Var stream;
    php::Var ret;

    // Stmt_Expression [99:99]
    file_open_type = "w+";
    // Stmt_If [100:101]
    if (binary_output) {
        // Stmt_Expression [101:101]
        file_open_type = "w+b";
    }

    // Stmt_Expression [103:103]
    stream = php::call(php::fopen, {"php://memory", file_open_type});
    // Stmt_If [104:105]
    if (php::same(false, stream)) {
        // Stmt_Return [105:105]
        return "";
    }

    // Stmt_Expression [107:107]
    php_write_mandelbrot_to_stream(php::to_int(w), php::to_int(h), stream, php::to_bool(binary_output));
    // Stmt_Expression [108:108]
    php::call(php::rewind, {stream});
    // Stmt_Expression [109:109]
    ret = php::call(php::stream_get_contents, {stream});
    // Stmt_Expression [110:110]
    php::call(php::fclose, {stream});
    // Stmt_Return [111:111]
    return ret;
}

void php_main() {
    // Stmt_Global [116:116]

    // Stmt_If [117:120]
    if (php::to_bool((php::len(argv)) < (php::toInt(3L)))) {
        // Stmt_Echo [118:118]
        php::echo("Usage: php mandelbrot.php <width> <height> [<output_file>]\n");
        // Stmt_Expression [119:119]
        php::exit(1L);
    }

    // Stmt_Echo [121:121]
    php::echo(php_treffynnon_mandelbrot_to_mem(
        php::to_int(argv.offsetGet(2L)), php::to_int(argv.offsetGet(2L)), php::to_bool(false)));
}
