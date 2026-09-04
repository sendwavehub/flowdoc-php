<?php

declare(strict_types=1);

namespace Flowdoc\Tests;

use Flowdoc\NativeParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for .flowc ("compact flow") -- a denser text sibling of .flow, see
 * docs/FORMAT_FLOWC.md. Unlike .flowb, this is a pure string-transform API
 * (parseFlowCompact/writeFlowCompact), mirroring parseFlow's own shape, not
 * .flowb's file-based saveFlowb/loadFlowb one -- so no temp-file setup here.
 *
 * Exercises the real FFI calls into flowdoc_parse_compact/flowdoc_write_compact
 * (flowdoc-core/src/ffi.rs), same as NativeParserTest.php does for parseFlow.
 */
final class FlowCompactTest extends TestCase
{
    public function testParsesBasicFlowcText(): void
    {
        $data = "id:1\nname:Test\n";

        $result = NativeParser::parseFlowCompact($data);

        self::assertCount(1, $result);
        self::assertSame('1', $result[0]['id']);
        self::assertSame('Test', $result[0]['name']);
    }

    public function testWriteThenParseRoundtrip(): void
    {
        $records = [
            ['id' => '1', 'name' => 'Test'],
            ['id' => '2', 'name' => 'Test2'],
        ];

        $flowc = NativeParser::writeFlowCompact($records);
        $result = NativeParser::parseFlowCompact($flowc);

        self::assertCount(2, $result);
        self::assertSame('1', $result[0]['id']);
        self::assertSame('Test', $result[0]['name']);
        self::assertSame('2', $result[1]['id']);
        self::assertSame('Test2', $result[1]['name']);
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        self::assertSame([], NativeParser::parseFlowCompact(''));
    }

    public function testEmptyRecordsArrayWritesEmptyString(): void
    {
        self::assertSame('', NativeParser::writeFlowCompact([]));
    }

    public function testBlankLineSeparatedMultiRecord(): void
    {
        $data = "id:1\nname:Test\n\nid:2\nname:Test2\n";

        $result = NativeParser::parseFlowCompact($data);

        self::assertCount(2, $result);
        self::assertSame('1', $result[0]['id']);
        self::assertSame('Test', $result[0]['name']);
        self::assertSame('2', $result[1]['id']);
        self::assertSame('Test2', $result[1]['name']);
    }

    public function testMultibyteUtf8ValuePreserved(): void
    {
        $data = "name:café ❤\n";

        $result = NativeParser::parseFlowCompact($data);

        self::assertCount(1, $result);
        self::assertSame('café ❤', $result[0]['name']);
    }

    public function testMultibyteUtf8ValueRoundtrip(): void
    {
        $records = [['name' => 'café ❤']];

        $flowc = NativeParser::writeFlowCompact($records);
        $result = NativeParser::parseFlowCompact($flowc);

        self::assertSame($records, $result);
    }

    public function testValueContainingColonPreserved(): void
    {
        $data = "url:https://example.com/path?x=1\n";

        $result = NativeParser::parseFlowCompact($data);

        self::assertCount(1, $result);
        self::assertSame('https://example.com/path?x=1', $result[0]['url']);
    }

    public function testCrossFormatEquivalenceWithParseFlow(): void
    {
        $flowData = "Record\n  id: 1\n  name: Test\nRecord\n  id: 2\n  name: Test2\n";
        $flowcData = "id:1\nname:Test\n\nid:2\nname:Test2\n";

        $flowResult = NativeParser::parseFlow($flowData);
        $flowcResult = NativeParser::parseFlowCompact($flowcData);

        // Field order comes from a Rust HashMap and isn't guaranteed (see
        // docs/FORMAT_FLOWC.md §5) -- compare per-record key/value content,
        // not array order, for semantic equivalence between the two formats.
        self::assertCount(count($flowResult), $flowcResult);
        foreach ($flowResult as $i => $record) {
            ksort($record);
            $other = $flowcResult[$i];
            ksort($other);
            self::assertSame($record, $other);
        }
    }
}
