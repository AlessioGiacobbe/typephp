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

`wit-bindgen`, Autoconf, Bison, re2c, and the PHP/PHPX source trees are SDK
producer dependencies only. They are never searched for or invoked by
`tpc --wasm`.
