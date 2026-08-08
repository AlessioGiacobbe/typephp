# TypePHP WASI SDK layout

TypePHP application builds never compile PHP, PHPX, GMP, MPFR, or mpdecimal.
The integrated PHPX installer places their prebuilt `wasm32-wasip2` SDK at:

```text
<phpx>/wasm/wasm32-wasip2/
├── include/
│   ├── php/                 PHP installed and generated headers
│   ├── phpx/                PHPX public and TypePHP runtime headers
│   ├── gmp.h
│   ├── gmpxx.h
│   ├── mpfr.h
│   ├── mpdecimal.h
│   └── decimal.hh
├── lib/
│   ├── libphp.a
│   ├── libphpx.a
│   ├── libgmp.a
│   ├── libgmpxx.a
│   ├── libmpfr.a
│   ├── libmpdec.a
│   └── libmpdec++.a
└── .typephp-wasi-sdk-abi
```

The ABI file must contain exactly:

```text
typephp-wasip2-sdk-abi-v2
```

TypePHP locates PHPX through the existing `PHPX_HOME` setting, Composer's
`swoole/phpx` installation metadata, or `vendor/swoole/phpx`. TypePHP developers
who independently clone and build the matching `php-8.5.9-wasm` and PHPX
repositories install the complete SDK below that PHPX checkout. There is no
additional WASI SDK environment variable and no set of per-library search
paths: all headers, archives, and the ABI marker must be installed together so
an application cannot accidentally mix incompatible builds.

Autoconf, Bison, re2c, Rust, upstream `wit-bindgen`, and the PHP/PHPX source
trees are SDK producer dependencies only. They are never searched for by an
application build. PHPX release packages include the pinned host-side
`phpx-wit-bindgen` needed for application-specific exports below
`<phpx>/wasm/bin/<host-os>-<host-arch>/`.

## Language-level component exports

A WASI command remains the default and defines `main()`. A callable component
uses library mode and exports explicitly annotated, statically typed functions:

```php
#[WasmExport]
function add(int $left, int $right): int
{
    return $left + $right;
}

#[WasmExport(name: 'greet-user')]
function greetUser(string $name): string
{
    return "Hello, $name";
}
```

```yaml
name: calculator
mode: library
wasm: browser
wasm-package: app:calculator@1.0.0
wasm-world: calculator
sources:
  - src
```

The first ABI version supports `bool`, `int`, `float`, `string`, nullable
versions of those types, and `void`. PHP `int` maps to WIT `s64` and therefore
to JavaScript `bigint`. Untyped values, arrays, objects, references, variadic
or optional parameters, generators, and exported methods are compile-time
errors. Every exported call uses a WIT `result` so PHP exceptions and Zend
bailouts are converted before crossing the Canonical ABI boundary.

`tpc` writes the generated `.wit` next to its intermediate interface manifest,
invokes PHPX's bundled generator, and links a reactor component. Browser mode
then uses Jco exactly as command components do. The generated
`create-runtime()` function returns a WIT `runtime` resource. Creating it
starts one NTS PHP request; dropping it runs `RSHUTDOWN` before the request
memory pool is released. Methods on the same resource must remain serialized,
and only one runtime resource may be active in a component instance.

Jco exposes the resource as a JavaScript class. Browser code creates one
runtime and reuses it for hot calls:

```js
const runtime = await api.createRuntime();
console.log(await runtime.add(20n, 22n));
console.log(await runtime.greetUser('TypePHP'));

// Release deterministically instead of waiting for JavaScript GC.
runtime[Symbol.dispose]();
```

Top-level WIT `result` errors are surfaced by Jco as JavaScript exceptions.
