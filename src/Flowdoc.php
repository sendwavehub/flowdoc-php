<?php

declare(strict_types=1);

namespace Flowdoc;

use FFI;
use RuntimeException;

/**
 * PHP FFI binding for the native flowdoc-core Rust library.
 *
 * Wraps the C ABI exported by flowdoc-core/src/ffi.rs:
 *   void* flowdoc_parse(const uint8_t* data_ptr, size_t data_len);
 *   void  flowdoc_free_string(void* ptr);
 *
 * Every non-null pointer returned by flowdoc_parse is copied into a PHP
 * string via FFI::string() and then released with flowdoc_free_string()
 * exactly once, so no native memory is leaked across calls.
 */
final class NativeParser
{
    /**
     * Fallback directory containing this monorepo's own cargo release
     * build output, used only when nothing else below finds the library.
     * The exact filename inside it is OS-dependent (see libraryPath()).
     */
    private const RELEASE_DIR = __DIR__ . '/../../../flowdoc-core/target/release/';

    /**
     * Package-relative directory for a bundled prebuilt native library,
     * one subdirectory per platform (Platform::dirName()). Populated, when
     * it's populated at all, by Installer::postInstall() (a Composer
     * post-install-cmd/post-update-cmd hook, see composer.json) --
     * downloaded and SHA-256-verified against native-checksums.json, not
     * bundled directly in this package. Checked ahead of SYSTEM_LIB_DIRS.
     */
    private const BUNDLED_NATIVE_DIR = __DIR__ . '/../native/';

    /**
     * Directories checked (in order, after FLOWDOC_NATIVE_LIB_PATH and
     * BUNDLED_NATIVE_DIR) for a system-installed native library, before
     * falling back to RELEASE_DIR. Composer installs this package into
     * someone else's vendor/ tree, where RELEASE_DIR's monorepo-relative
     * path doesn't exist -- see libraryPath()'s doc comment for the full
     * resolution order and how a consumer is expected to make the
     * library available.
     */
    private const SYSTEM_LIB_DIRS = [
        '/usr/local/lib/',
        '/opt/homebrew/lib/',
        '/usr/lib/',
    ];

    private const CDEF = <<<'CDEF'
        void* flowdoc_parse(const uint8_t* data_ptr, size_t data_len);
        void flowdoc_free_string(void* ptr);
        bool flowdoc_parse_binary(const uint8_t* data_ptr, size_t data_len, uint8_t** out_ptr, size_t* out_len);
        void flowdoc_free_buffer(uint8_t* ptr, size_t len);
        CDEF;

    private static ?FFI $ffi = null;

    private function __construct()
    {
        // Static-only utility class.
    }

    private static function init(): FFI
    {
        if (self::$ffi === null) {
            if (!extension_loaded('FFI')) {
                throw new RuntimeException(
                    'The FFI extension is not loaded. Enable it (php.ini "extension=ffi") '
                    . 'and set ffi.enable=1, or run PHP with -d ffi.enable=1.'
                );
            }

            $libPath = self::libraryPath();
            if (!is_file($libPath)) {
                throw new RuntimeException(
                    'Native flowdoc_core library not found at ' . $libPath . '. '
                    . 'Build it with `cargo build --release` in flowdoc-core/ and set the '
                    . 'FLOWDOC_NATIVE_LIB_PATH environment variable to the resulting library '
                    . 'file, or install it into a system library directory (/usr/local/lib, '
                    . '/opt/homebrew/lib, /usr/lib).'
                );
            }

            self::$ffi = FFI::cdef(self::CDEF, $libPath);
        }

        return self::$ffi;
    }

    /**
     * Resolves the path to the native flowdoc_core library, in order:
     *
     *   1. $FLOWDOC_NATIVE_LIB_PATH, if set -- a full path to the library
     *      file itself. This is the documented way for a Composer
     *      consumer (installed into someone else's vendor/, where this
     *      package's own monorepo-relative fallback below doesn't exist)
     *      to point at a library they built or installed themselves, e.g.
     *      via `cargo build --release` against flowdoc-core, or a system
     *      package.
     *   2. BUNDLED_NATIVE_DIR, in case Installer::postInstall() downloaded
     *      and checksum-verified a prebuilt library there for this
     *      platform (see its own doc comment).
     *   3. Common system library directories (SYSTEM_LIB_DIRS), in case
     *      it was installed system-wide under the OS-standard filename.
     *   4. This monorepo's own cargo release build output (RELEASE_DIR)
     *      -- only reachable when this package is used from inside the
     *      FlowDoc-Pro monorepo itself (e.g. this binding's own test
     *      suite), not as an installed Composer dependency.
     *
     * The OS-appropriate filename (libflowdoc_core.so on Linux,
     * libflowdoc_core.dylib on macOS, flowdoc_core.dll on Windows) is
     * needed for steps 2-4 -- `cargo build --release` names its output
     * differently per platform, and PHP's FFI::cdef() needs the exact
     * filename, unlike .NET's DllImport which resolves it for you.
     */
    private static function libraryPath(): string
    {
        $envPath = getenv('FLOWDOC_NATIVE_LIB_PATH');
        if ($envPath !== false && $envPath !== '' && is_file($envPath)) {
            return $envPath;
        }

        $platformDir = Platform::dirName();
        $filename = Platform::libFilename();

        if ($platformDir !== null) {
            $bundledPath = self::BUNDLED_NATIVE_DIR . $platformDir . '/' . $filename;
            if (is_file($bundledPath)) {
                return $bundledPath;
            }
        }

        foreach (self::SYSTEM_LIB_DIRS as $dir) {
            if (is_file($dir . $filename)) {
                return $dir . $filename;
            }
        }

        return self::RELEASE_DIR . $filename;
    }

    /**
     * Parses FlowDoc-formatted text into an array of associative arrays
     * (string => string), one per record.
     *
     * @return array<int, array<string, string>>
     */
    public static function parseFlow(string $data): array
    {
        // The native side rejects a zero-length buffer allocation, and an
        // empty document has no records anyway, so short-circuit here.
        if ($data === '') {
            return [];
        }

        $ffi = self::init();
        $length = strlen($data);

        // Copy the PHP string into a native uint8_t[] buffer -- FFI cannot
        // pass a PHP string directly where a uint8_t* parameter is declared.
        $buffer = $ffi->new("uint8_t[$length]", false);
        FFI::memcpy($buffer, $data, $length);

        $resultPtr = $ffi->flowdoc_parse($buffer, $length);
        if ($resultPtr === null) {
            throw new RuntimeException('flowdoc_parse failed: input was not valid UTF-8.');
        }

        try {
            // flowdoc_parse returns void*; FFI::string() needs a char* to
            // know where the NUL terminator is.
            $charPtr = $ffi->cast('char*', $resultPtr);
            $json = FFI::string($charPtr);
        } finally {
            // Always free the native buffer, even if decoding throws below.
            $ffi->flowdoc_free_string($resultPtr);
        }

        /** @var array<int, array<string, string>>|null $records */
        $records = json_decode($json, true);
        if (!is_array($records)) {
            throw new RuntimeException('flowdoc_parse returned malformed JSON: ' . $json);
        }

        return $records;
    }

    /**
     * MEASURED SLOWER THAN parseFlow() -- use parseFlow() instead. Kept as
     * a documented negative result, not a recommended API.
     *
     * The hypothesis: parseFlow() pays for a Rust-side JSON encode AND a
     * PHP-side json_decode() on top of the actual FlowDoc parse, so this
     * would skip JSON entirely on both ends via flowdoc_parse_binary's
     * purpose-built wire format (length-prefixed raw bytes, see ffi.rs's
     * encode_binary doc comment), decoded with plain unpack()/substr().
     *
     * Measured result on the 1000-record fixture: ~0.87ms here vs. ~0.59ms
     * for parseFlow(). json_decode() is a single optimized C-extension
     * call; this method's decode loop is thousands of small unpack()/
     * substr() calls in interpreted PHP userland, and that overhead
     * dominates the JSON-round-trip savings it was meant to eliminate --
     * the same class of result sendwavehub/FlowDoc's own PERFORMANCE.md
     * documents for a different low-level PHP optimization attempt (their
     * known-gaps.md item 16). Confirmed real via
     * FlowdocNative.Tests-style back-to-back benchmarking, not assumed.
     *
     * @return array<int, array<string, string>>
     */
    public static function parseFlowBinary(string $data): array
    {
        if ($data === '') {
            return [];
        }

        $ffi = self::init();
        $length = strlen($data);

        $buffer = $ffi->new("uint8_t[$length]", false);
        FFI::memcpy($buffer, $data, $length);

        $outPtr = $ffi->new('uint8_t*');
        $outLen = $ffi->new('size_t');

        $ok = $ffi->flowdoc_parse_binary($buffer, $length, FFI::addr($outPtr), FFI::addr($outLen));
        if (!$ok) {
            throw new RuntimeException('flowdoc_parse_binary failed: input was not valid UTF-8.');
        }

        try {
            $raw = FFI::string($outPtr, $outLen->cdata);
        } finally {
            $ffi->flowdoc_free_buffer($outPtr, $outLen->cdata);
        }

        return self::decodeBinary($raw);
    }

    /**
     * Decodes flowdoc_parse_binary's wire format:
     *   u32 record_count
     *   per record: u32 field_count, per field: u32 key_len, key_bytes, u32 value_len, value_bytes
     * All integers little-endian, matching Rust's to_le_bytes() on the encode side.
     *
     * @return array<int, array<string, string>>
     */
    private static function decodeBinary(string $buf): array
    {
        $offset = 0;

        $readU32 = static function () use ($buf, &$offset): int {
            /** @var array{1:int} $unpacked */
            $unpacked = unpack('V', $buf, $offset);
            $offset += 4;
            return $unpacked[1];
        };

        $recordCount = $readU32();
        $records = [];
        for ($i = 0; $i < $recordCount; $i++) {
            $fieldCount = $readU32();
            $record = [];
            for ($j = 0; $j < $fieldCount; $j++) {
                $keyLen = $readU32();
                $key = substr($buf, $offset, $keyLen);
                $offset += $keyLen;
                $valLen = $readU32();
                $value = substr($buf, $offset, $valLen);
                $offset += $valLen;
                $record[$key] = $value;
            }
            $records[] = $record;
        }

        return $records;
    }
}
