# flowdoc (PHP)

PHP FFI binding for [FlowDoc](https://github.com/sendwavehub/flowdoc-bindings)
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
- `NativeParser::parseFlowCompact($data)` / `NativeParser::writeFlowCompact($records)`
  — `.flowc` ("compact flow", see `docs/FORMAT_FLOWC.md`), a denser text
  sibling of `.flow` with no `Record` header, no indentation, and a blank
  line separating records. Same `array<int, array<string, string>>` shape
  and FFI/JSON round-trip discipline as `parseFlow`, over
  `flowdoc_parse_compact`/`flowdoc_write_compact` instead of `flowdoc_parse`.
  A pure string-transform API, like `parseFlow` — not file-based like
  `Flowb::saveFlowb`/`loadFlowb` below.
- `Flowb::saveFlowb($path, $records)` / `Flowb::loadFlowb($path)` — `.flowb`,
  the MessagePack-encoded binary counterpart to `.flow`. Same
  `array<int, array<string, string>>` shape `parseFlow()` returns, just
  written to/read from disk as MessagePack instead of parsed from
  `key: value` text. Implemented entirely in pure PHP with
  [`rybakit/msgpack`](https://github.com/rybakit/msgpack.php) — deliberately
  independent of `flowdoc-core` and the FFI boundary above (no native
  library required), matching this repo's finding that PHP's FFI/native
  path is slower than plain PHP for this kind of encode/decode workload.

```php
use Flowdoc\Flowb;

Flowb::saveFlowb('data.flowb', [['id' => '1', 'name' => 'Test']]);
$records = Flowb::loadFlowb('data.flowb');
// [['id' => '1', 'name' => 'Test']]
```

```php
use Flowdoc\NativeParser;

$flowc = NativeParser::writeFlowCompact([['id' => '1', 'name' => 'Test']]);
// "id:1\nname:Test"
$records = NativeParser::parseFlowCompact($flowc);
// [['id' => '1', 'name' => 'Test']]
```

## Licensing (soft gate)

`NativeParser::parseFlow()` (and every other parse/write method) works
identically whether or not a license key is configured — there is no
Pro-exclusive capability gated by this yet. If `FLOWDOC_LICENSE_KEY` is
set, `Flowdoc\License::status()` validates it against
`FLOWDOC_LICENSE_SERVER + /api/licenses/validate` (no default server —
validation is skipped entirely if this isn't set too) and logs a warning
(`error_log('flowdoc: ...')`) on an invalid key or an unreachable server.

Unlike `bindings/python`'s `license_status()` and `bindings/nodejs`'s
`licenseStatus()`, this check does **not** fire automatically — PHP has no
"on import" hook comparable to a Python module's top-level code or a
Node `require()` call (Composer's PSR-4 autoloading only registers a
namespace-to-directory mapping; it executes nothing until a caller
actually calls something), and a typical PHP process is a single
short-lived CLI script or php-fpm request that may never touch licensing
at all. So `License::status()` is explicit-only: call it yourself when you
want the check to run. Its result is still cached for the rest of the
process, so a long-running worker (RoadRunner, Swoole, a queue consumer)
that calls it repeatedly only pays the network cost once — call
`License::resetStatusCache()` to force a fresh check.

```php
use Flowdoc\License;

$status = License::status();
// ['checked' => true, 'valid' => true|false|null, 'error' => string|null]
```

`valid` is `null` when there was nothing to check (no key configured) or
nothing could be checked (no server configured, or unreachable) — see
`src/License.php` for the full behavior. Never throws.

In production, set `FLOWDOC_LICENSE_SERVER=https://license-admin.sendwavehub.tech/api`
(the trailing `/api` is required — see `RELEASING.md`'s "Production
license server" section for why). There is no default; validation is
skipped entirely without it.

### Activation

`License::activate(string $activatedBy, ?string $activationIp = null, ?array $metadata = null)`
is a separate, explicit call — like `status()`, it never runs
automatically, and it's a mutating call (it flips the license to
"Activated" server-side, unlike `/validate`'s read-only check). Call it
once, e.g. on first run/install:

```php
use Flowdoc\License;

$result = License::activate('install-script');
// ['success' => true, 'error' => null, 'message' => ..., 'tier' => ..., 'seats' => ...,
//  'expiresAt' => ..., 'customerId' => ..., 'signedLicenseArtifact' => ...]
// or, on failure: ['success' => false, 'error' => '...', ...other fields null]
```

Posts to `FLOWDOC_LICENSE_SERVER + /licenses/<FLOWDOC_LICENSE_KEY>/activate`
(a single `/api/` segment, since `FLOWDOC_LICENSE_SERVER` is expected to
already carry one — see `/validate`'s doubled `/api/api/` above). Cache
`signedLicenseArtifact` yourself if you need it later; this method doesn't
persist anything. Never throws.

## Requirements

- PHP 8.1+ with the `FFI` extension enabled (`extension=ffi` in `php.ini`,
  and `ffi.enable=1` — or run with `-d ffi.enable=1` for CLI scripts).
- The native `flowdoc_core` library, resolved in this order:
  1. **`FLOWDOC_NATIVE_LIB_PATH` environment variable**, set to the full
     path of the library file — the documented way to point this package
     at a library you've built yourself. This requires access to the
     private `flowdoc-core` source (not available publicly) and a Rust
     toolchain:
     ```bash
     cd flowdoc-core
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
     restricted environments). This is the normal path for a real
     `composer install` — see `native-checksums.json` for exactly which
     versions/platforms currently have a published native library.
  3. A system library directory (`/usr/local/lib`, `/opt/homebrew/lib`,
     `/usr/lib`), under the OS-standard filename
     (`libflowdoc_core.so`/`.dylib` or `flowdoc_core.dll`).
  4. This binding's own private source checkout's `flowdoc-core/target/release/`
     build output — only reachable from inside that checkout (e.g. this
     binding's own test suite), not as an installed Composer dependency.

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

See the [FlowDoc project](https://github.com/sendwavehub/flowdoc-bindings)
for the format overview, benchmark numbers, and links to every other
language binding (Rust, Go, Python, Node.js, C#, C++).
