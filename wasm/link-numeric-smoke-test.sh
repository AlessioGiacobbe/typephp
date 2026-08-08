#!/usr/bin/env bash

set -euo pipefail

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
compiler_dir=$(cd "${script_dir}/.." && pwd)
phpx_home=${PHPX_HOME:-${compiler_dir}/vendor/swoole/phpx}
prefix=${TYPEPHP_WASI_SDK_DIR:-${phpx_home}/wasm/wasm32-wasip2}
output=${TYPEPHP_WASM_NUMERIC_OUTPUT:-/tmp/typephp-wasm-numeric.wasm}
wasi_cxx=${TYPEPHP_WASI_CXX:-$(command -v wasm32-wasip2-clang++ || true)}

if [[ -z "${wasi_cxx}" ]]; then
    echo "Required WASI tool 'wasm32-wasip2-clang++' was not found in PATH" >&2
    exit 1
fi

for library in libgmp.a libgmpxx.a libmpfr.a libmpdec.a libmpdec++.a; do
    if [[ ! -f "${prefix}/lib/${library}" ]]; then
        echo "WASI numeric library not found: ${prefix}/lib/${library}" >&2
        exit 1
    fi
done

"${wasi_cxx}" \
    -O0 \
    -std=c++17 \
    -fwasm-exceptions \
    -mllvm -wasm-enable-sjlj \
    -mllvm -wasm-use-legacy-eh=false \
    -I"${prefix}/include" \
    "${script_dir}/numeric-smoke-test.cc" \
    -L"${prefix}/lib" \
    -lmpdec++ -lmpdec -lmpfr -lgmpxx -lgmp \
    -lwasi-emulated-signal \
    -lsetjmp -lunwind -lm \
    -o "${output}"

echo "Linked numeric WASI smoke test: ${output}"
