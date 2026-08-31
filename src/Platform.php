<?php

declare(strict_types=1);

namespace Flowdoc;

use RuntimeException;

/**
 * OS-detection shared by NativeParser (locating an already-present library)
 * and Installer (downloading one for the current platform). Kept as the
 * single source of truth for the PHP_OS_FAMILY -> {directory name, cargo
 * build filename} mapping so the two never drift apart.
 */
final class Platform
{
    private function __construct()
    {
        // Static-only utility class.
    }

    /**
     * Lowercased directory name used under native/ and in
     * native-checksums.json (darwin/linux/windows), or null for an OS this
     * binding has no cargo build target for.
     */
    public static function dirName(): ?string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Linux' => 'linux',
            'Windows' => 'windows',
            default => null,
        };
    }

    /**
     * `cargo build --release`'s output filename for the current OS
     * (libflowdoc_core.so on Linux, libflowdoc_core.dylib on macOS,
     * flowdoc_core.dll on Windows).
     *
     * @throws RuntimeException if the current OS has no cargo build target.
     */
    public static function libFilename(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'libflowdoc_core.dylib',
            'Linux' => 'libflowdoc_core.so',
            'Windows' => 'flowdoc_core.dll',
            default => throw new RuntimeException('Unsupported OS: ' . PHP_OS_FAMILY),
        };
    }
}
