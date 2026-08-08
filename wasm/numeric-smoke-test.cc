#include <gmpxx.h>
#include <mpfr.h>
#include <decimal.hh>

#include <cstdio>
#include <string>

int main()
{
    mpz_class integer("18446744073709551616");
    integer = integer * integer + 7;
    if (integer.get_str() != "340282366920938463463374607431768211463") {
        return 1;
    }

    mpfr_t value;
    mpfr_init2(value, 256);
    if (mpfr_set_str(value, "2", 10, MPFR_RNDN) != 0) {
        mpfr_clear(value);
        return 2;
    }
    mpfr_sqrt(value, value, MPFR_RNDN);
    char float_buffer[96];
    mpfr_snprintf(float_buffer, sizeof(float_buffer), "%.40RNf", value);
    mpfr_clear(value);
    if (std::string(float_buffer) != "1.4142135623730950488016887242096980785697") {
        return 3;
    }

    decimal::Decimal small_decimal("1.25");
    if (small_decimal.to_sci() != "1.25") {
        std::fprintf(stderr, "unexpected parsed Decimal: %s\n", small_decimal.to_sci().c_str());
        return 4;
    }
    small_decimal *= decimal::Decimal("8");
    if (small_decimal.to_sci() != "10.00") {
        std::fprintf(stderr, "unexpected small Decimal result: %s\n", small_decimal.to_sci().c_str());
        return 5;
    }

    decimal::Context decimal_context(32);
    decimal::Decimal decimal_value("12345678901234567890.125");
    decimal_value = decimal_value.mul(decimal::Decimal("8"), decimal_context);
    const std::string decimal_string = decimal_value.to_sci();
    if (decimal_string != "98765431209876543121.000") {
        std::fprintf(stderr, "unexpected Decimal result: %s\n", decimal_string.c_str());
        return 6;
    }

    std::puts("TYPEPHP_WASM_NUMERIC_OK");
    return 0;
}
