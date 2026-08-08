#!/usr/bin/env bash

set -euo pipefail

script_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
compiler_dir=$(cd "${script_dir}/.." && pwd)
output=${TYPEPHP_WASM_TEST_OUTPUT:-/tmp/typephp-wasm-high-precision.wasm}
wasmtime_bin=${TYPEPHP_WASMTIME:-$(command -v wasmtime || true)}
if [[ -z "${wasmtime_bin}" ]]; then
    echo "Required WASI tool 'wasmtime' was not found in PATH" >&2
    exit 1
fi

output_dir=$(dirname "${output}")
output_name=$(basename "${output}")
(
    cd "${output_dir}"
    php "${compiler_dir}/bin/tpc.php" --wasm=component "${script_dir}/examples/high-precision.php"
    if [[ high-precision.wasm != "${output_name}" ]]; then
        mv high-precision.wasm "${output_name}"
    fi
)

actual=$(XDG_CACHE_HOME=${XDG_CACHE_HOME:-/tmp/typephp-wasmtime-cache} \
    "${wasmtime_bin}" -S http "${output}")
expected=$'1111111101111111110111111111010\n1000000000000000000000000000001\n12348.14159265358979324'

if [[ "${actual}" != "${expected}" ]]; then
    echo "Unexpected TypePHP/WASI output:" >&2
    printf '%s\n' "${actual}" >&2
    exit 1
fi

printf '%s\n' "${actual}"
echo "TypePHP/WASI integration test passed"
