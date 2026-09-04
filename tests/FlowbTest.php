<?php

declare(strict_types=1);

namespace Flowdoc\Tests;

use Flowdoc\Flowb;
use Flowdoc\NativeParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for .flowb, the MessagePack-encoded binary counterpart to .flow.
 *
 * Deliberately independent of the native FFI path tested in
 * NativeParserTest.php -- Flowb::saveFlowb()/loadFlowb() are pure PHP
 * (rybakit/msgpack), operating only on the already-parsed
 * array<int, array<string,string>> shape that NativeParser::parseFlow()
 * returns, per this repo's CLAUDE.md design decision for this task.
 */
final class FlowbTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/flowdoc-flowb-test-' . uniqid();
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmpDir);
    }

    public function testSaveAndLoadRoundtrip(): void
    {
        $records = [
            ['id' => '1', 'name' => 'Test'],
            ['id' => '2', 'name' => 'Test2'],
        ];
        $path = $this->tmpDir . '/data.flowb';

        Flowb::saveFlowb($path, $records);
        $result = Flowb::loadFlowb($path);

        self::assertSame($records, $result);
    }

    public function testRoundtripMatchesParseFlowOutput(): void
    {
        $data = "Record\n  id: 1\n  name: Test\nRecord\n  id: 2\n  name: Test2\n";
        $records = NativeParser::parseFlow($data);
        $path = $this->tmpDir . '/data.flowb';

        Flowb::saveFlowb($path, $records);
        $result = Flowb::loadFlowb($path);

        self::assertSame($records, $result);
    }

    public function testEmptyArrayRoundtrip(): void
    {
        $path = $this->tmpDir . '/empty.flowb';

        Flowb::saveFlowb($path, []);
        $result = Flowb::loadFlowb($path);

        self::assertSame([], $result);
    }

    public function testEmptyRecordRoundtrip(): void
    {
        $records = [[], ['id' => '1']];
        $path = $this->tmpDir . '/data.flowb';

        Flowb::saveFlowb($path, $records);
        $result = Flowb::loadFlowb($path);

        self::assertSame($records, $result);
    }

    public function testMultibyteUtf8ValuePreserved(): void
    {
        $records = [['name' => 'café ❤']];
        $path = $this->tmpDir . '/data.flowb';

        Flowb::saveFlowb($path, $records);
        $result = Flowb::loadFlowb($path);

        self::assertSame($records, $result);
    }

    public function testFlowbSmallerThanJsonForTypicalData(): void
    {
        $records = [];
        for ($i = 0; $i < 100; $i++) {
            $records[] = ['id' => (string) $i, 'name' => "Item$i"];
        }
        $path = $this->tmpDir . '/data.flowb';

        Flowb::saveFlowb($path, $records);
        $flowbSize = filesize($path);

        $jsonSize = strlen(json_encode($records));

        self::assertLessThan($jsonSize, $flowbSize);
    }
}
