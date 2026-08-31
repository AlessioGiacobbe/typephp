# EXT/LIB integration tests

This suite protects build-mode boundaries rather than duplicating the PHP
syntax coverage in `tests/compiler`.

- `ext/lifecycle` builds a real Zend extension and loads it through CLI,
  `php -S`, and PHP-FPM. The long-running hosts alternate implementations of
  the same request-local class and function, while the extension also calls an
  internal class and method. This protects request cache cleanup and persistent
  cache reuse across repeated RINIT/RSHUTDOWN cycles.
- `lib` builds a provider library, consumes its generated `@import-library`
  stub from a second TypePHP binary, links the two artifacts, and runs the
  consumer. Both modes include a throwing `main()` declaration to verify that
  only bin mode executes the entrypoint.

Run from the repository root:

```sh
PHPX_HOME=../phpx php bin/run-integration-tests.php \
  --compiler=./tpc \
  --php="$(command -v php)" \
  --php-fpm="$(php-config --prefix)/sbin/php-fpm"
```

Successful runs remove their temporary build tree. On failure the generated
C++ sources, shared libraries, server configuration, and logs remain under
`build/integration-*` for CI artifact collection. Pass `--keep` to retain a
successful build as well. `--suite=ext` and `--suite=lib` can isolate one mode
while debugging; the default is `--suite=all`.
