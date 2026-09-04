<?php

declare(strict_types=1);

namespace Flowdoc;

use MessagePack\MessagePack;
use RuntimeException;

/**
 * .flowb: the MessagePack-encoded binary counterpart to .flow.
 *
 * Same parsed shape NativeParser::parseFlow() returns --
 * array<int, array<string, string>>, one associative array per record --
 * just MessagePack-encoded instead of `key: value` text.
 *
 * Deliberately independent of flowdoc-core and the FFI boundary: this is
 * pure PHP over the already-parsed native structure, using the pure-PHP
 * rybakit/msgpack package. Per this repo's CLAUDE.md, PHP's FFI/native
 * path (parseFlowBinary) was tried for exactly this kind of encode/decode
 * workload and measured SLOWER than plain PHP, so this class does not
 * reach for FFI or a native msgpack extension.
 */
final class Flowb
{
    private function __construct()
    {
        // Static-only utility class.
    }

    /**
     * MessagePack-encodes $records and writes them to $path.
     *
     * @param array<int, array<string, string>> $records
     */
    public static function saveFlowb(string $path, array $records): void
    {
        $packed = MessagePack::pack($records);

        if (file_put_contents($path, $packed) === false) {
            throw new RuntimeException('Failed to write .flowb file: ' . $path);
        }
    }

    /**
     * Reads $path and MessagePack-decodes it back into the same
     * array<int, array<string, string>> shape saveFlowb() was given.
     *
     * @return array<int, array<string, string>>
     */
    public static function loadFlowb(string $path): array
    {
        $packed = file_get_contents($path);
        if ($packed === false) {
            throw new RuntimeException('Failed to read .flowb file: ' . $path);
        }

        /** @var array<int, array<string, string>> $records */
        $records = MessagePack::unpack($packed);

        return $records;
    }
}
