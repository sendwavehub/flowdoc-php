<?php

declare(strict_types=1);

namespace Flowdoc\Tests;

use Flowdoc\NativeParser;
use PHPUnit\Framework\TestCase;

final class NativeParserTest extends TestCase
{
    public function testParsesTwoRecordsWithCorrectFields(): void
    {
        $data = <<<FLOW
        Record
          id: 1
          name: Test
        Record
          id: 2
          name: Test2

        FLOW;

        $result = NativeParser::parseFlow($data);

        self::assertCount(2, $result);
        self::assertSame('1', $result[0]['id']);
        self::assertSame('Test', $result[0]['name']);
        self::assertSame('2', $result[1]['id']);
        self::assertSame('Test2', $result[1]['name']);
    }

    public function testPreservesColonInValue(): void
    {
        $data = "Record\n  url: https://example.com/path?x=1\n";

        $result = NativeParser::parseFlow($data);

        self::assertCount(1, $result);
        self::assertSame('https://example.com/path?x=1', $result[0]['url']);
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        self::assertSame([], NativeParser::parseFlow(''));
    }

    public function testBenchmarkAgainstFixture(): void
    {
        $path = __DIR__ . '/../../../benchmarks/data_1000.flow';
        self::assertFileExists($path);

        $data = file_get_contents($path);
        self::assertIsString($data);

        // Warm up.
        $records = NativeParser::parseFlow($data);
        self::assertCount(1000, $records);

        $iterations = 50;
        $start = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            NativeParser::parseFlow($data);
        }
        $elapsedMs = (microtime(true) - $start) * 1000.0;
        $avgMs = $elapsedMs / $iterations;

        fwrite(STDERR, sprintf(
            "\n[benchmark] flowdoc_parse: %d iterations of data_1000.flow, avg %.4f ms/call\n",
            $iterations,
            $avgMs
        ));

        // Generous sanity bound -- not a strict performance assertion.
        self::assertLessThan(50.0, $avgMs, 'Native parse is unexpectedly slow');
    }
}
