# flowdoc (PHP)

PHP FFI binding for [FlowDoc](https://github.com/jomynn/FlowDoc-Pro-Performance)
— a fast serialization format: indent-delimited `key: value` records, parsed
by a shared Rust core (`flowdoc-core`) linked over PHP's `FFI` extension.

```php
use Flowdoc\NativeParser;

$records = NativeParser::parseFlow("Record\n  id: 1\n  name: Test\n");
// [['id' => '1', 'name' => 'Test']]
```

## Entry points

- `NativeParser::parseFlow($data)` — over `flowdoc_parse`'s JSON string,
  decoded with `json_decode()`. **Use this one.**
- `NativeParser::parseFlowBinary($data)` — over `flowdoc_parse_binary`'s
  length-prefixed binary wire format, decoded in a pure-PHP loop of
  `unpack()`/`substr()` calls. **Measured slower than `parseFlow`, not
  faster** — kept as a documented negative result (see its docblock in
  `src/Flowdoc.php`): `json_decode()` is a single optimized C-extension
  call, and thousands of small interpreted-PHP `unpack()` calls cost more
  than the JSON round-trip they were meant to avoid.

## Requirements

- PHP 8.1+ with the `FFI` extension enabled (`extension=ffi` in `php.ini`,
  and `ffi.enable=1` — or run with `-d ffi.enable=1` for CLI scripts).
- The native `flowdoc_core` library, resolved in this order:
  1. **`FLOWDOC_NATIVE_LIB_PATH` environment variable**, set to the full
     path of the library file — the documented way to point this package
     at a library you've built yourself. Build one with:
     ```bash
     git clone https://github.com/jomynn/FlowDoc-Pro-Performance
     cd FlowDoc-Pro-Performance/flowdoc-core
     cargo build --release
     export FLOWDOC_NATIVE_LIB_PATH=$(pwd)/target/release/libflowdoc_core.dylib  # .so on Linux, flowdoc_core.dll on Windows
     ```
  2. `native/{darwin,linux,windows}/<filename>`, relative to this
     package. Populated automatically by `composer install`/`composer
     update` via a `post-install-cmd`/`post-update-cmd` hook
     (`Flowdoc\Installer::postInstall`, see `src/Installer.php`): it looks
     up the installed package version and current platform in
     `native-checksums.json`, downloads the matching GitHub Release
     asset, and **verifies its SHA-256 against the manifest before
     writing anything** — a mismatch is discarded, not written. This step
     never fails your `composer install`: no network, no published
     version, or a checksum mismatch all just skip with a message and
     fall through to options 3/4 below. Set
     `FLOWDOC_SKIP_NATIVE_DOWNLOAD=1` to skip it outright (offline CI,
     restricted environments). No release has been cut yet, so today this
     step always skips with "no published native library ... yet" — see
     `native-checksums.json`'s own comments for exactly what's missing.
  3. A system library directory (`/usr/local/lib`, `/opt/homebrew/lib`,
     `/usr/lib`), under the OS-standard filename
     (`libflowdoc_core.so`/`.dylib` or `flowdoc_core.dll`).
  4. This monorepo's own `flowdoc-core/target/release/` build output —
     only reachable when this package is used from inside the
     `FlowDoc-Pro-Performance` repo itself (e.g. this binding's own test
     suite), not as an installed Composer dependency.

  If none of these resolve, `NativeParser` throws a `RuntimeException`
  naming the path it tried and how to fix it.

## Cutting a release (publishing a native library)

1. Run `.github/workflows/build-native-libs.yml` (`workflow_dispatch`) — its
   `php` job builds and tests on all three platforms, and `php-bundle`
   downloads them and computes a `checksums.json` artifact.
2. Attach each platform's file as a GitHub Release asset on the `vX.Y.Z`
   tag, named `libflowdoc_core-<platform>.<ext>` to match the `url`
   pattern in `native-checksums.json`.
3. Copy the matching `sha256` values from step 1's `checksums.json` into
   `native-checksums.json` under that version, and commit it.

Steps 2-3 are deliberately manual (or a separate, not-yet-written
automation job) rather than CI writing back into the repo on its own —
see `build-native-libs.yml`'s `php-bundle` job comment for why.

## Build & test

```bash
composer install
php -d ffi.enable=1 vendor/bin/phpunit tests/
```

See the [main repository](https://github.com/jomynn/FlowDoc-Pro-Performance)
for the format specification, benchmarks, and the other six language
bindings (Rust, Go, Python, Node.js, C#, C++).
