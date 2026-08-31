<?php

declare(strict_types=1);

/**
 * Plain, dependency-free test runner for the Flowdoc PHP FFI binding.
 * Does not require composer/phpunit -- only needs the FFI extension
 * enabled (php -d ffi.enable=1 tests/run.php).
 */

require __DIR__ . '/../src/Platform.php';
require __DIR__ . '/../src/Flowdoc.php';

use Flowdoc\NativeParser;

$failures = 0;
$passed = 0;

function check(string $label, bool $condition, string $detail = ''): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failures++;
        echo "FAIL: $label" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
}

// --- Test 1: two records parse with correct id/name -----------------------
$data = "Record\n  id: 1\n  name: Test\nRecord\n  id: 2\n  name: Test2\n";
$result = NativeParser::parseFlow($data);
check('two records parsed', count($result) === 2, 'count=' . count($result));
check('record 1 id', ($result[0]['id'] ?? null) === '1');
check('record 1 name', ($result[0]['name'] ?? null) === 'Test');
check('record 2 id', ($result[1]['id'] ?? null) === '2');
check('record 2 name', ($result[1]['name'] ?? null) === 'Test2');

// --- Test 2: value containing a colon (URL) is preserved -------------------
$dataUrl = "Record\n  url: https://example.com/path?x=1\n";
$resultUrl = NativeParser::parseFlow($dataUrl);
check(
    'URL value with colon preserved',
    ($resultUrl[0]['url'] ?? null) === 'https://example.com/path?x=1',
    'got=' . json_encode($resultUrl)
);

// --- Test 3: empty input returns empty array --------------------------------
$resultEmpty = NativeParser::parseFlow('');
check('empty input returns empty array', $resultEmpty === []);

// --- Test 3b: parseFlowBinary agrees with parseFlow on the same input ------
// (parseFlowBinary is measured slower -- see its docblock -- but must stay
// correct, since it's kept as a documented reference implementation.)
//
// PHP's == (not ===, and not a raw json_encode string comparison) is
// deliberate: each field comes from a separate Rust HashMap with its own
// randomized iteration order (RandomState is per-instance, not
// process-wide), so parseFlow's JSON-encode and parseFlowBinary's
// wire-encode of the *same logical record* can legitimately emit fields in
// different orders. A string/=== comparison is flaky whenever a record has
// more than one field -- confirmed by actually seeing it fail intermittently,
// not assumed. == compares key/value pairs regardless of order within each
// record while still requiring the records themselves to be in the same
// list order, which is exactly the property that should hold here.
$resultBinary = NativeParser::parseFlowBinary($data);
check('parseFlowBinary agrees with parseFlow', $resultBinary == $result);

// --- Test 4: benchmark against the shared 1000-record fixture --------------
$fixturePath = __DIR__ . '/../../../benchmarks/data_1000.flow';
if (!is_file($fixturePath)) {
    check('benchmark fixture exists', false, "missing $fixturePath");
} else {
    $benchData = file_get_contents($fixturePath);
    check('benchmark fixture readable', is_string($benchData) && $benchData !== '');

    // Warm up once and sanity-check record count.
    $warm = NativeParser::parseFlow($benchData);
    check('benchmark fixture parses to 1000 records', count($warm) === 1000, 'count=' . count($warm));

    $iterations = 50;
    $start = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        NativeParser::parseFlow($benchData);
    }
    $elapsedMs = (microtime(true) - $start) * 1000.0;
    $avgMs = $elapsedMs / $iterations;

    printf("BENCHMARK: %d iterations of data_1000.flow, avg %.4f ms/call (parseFlow, JSON round-trip)\n", $iterations, $avgMs);

    // Generous sanity bound -- not a strict pass/fail performance gate.
    check('benchmark average under generous sanity bound (50ms)', $avgMs < 50.0, sprintf('%.4fms', $avgMs));

    // Re-measures the documented parseFlowBinary-is-slower finding on every
    // run, so it can't silently drift stale if the implementation changes.
    $warmBinary = NativeParser::parseFlowBinary($benchData);
    check('parseFlowBinary also parses to 1000 records', count($warmBinary) === 1000, 'count=' . count($warmBinary));

    $startBinary = microtime(true);
    for ($i = 0; $i < $iterations; $i++) {
        NativeParser::parseFlowBinary($benchData);
    }
    $avgBinaryMs = ((microtime(true) - $startBinary) * 1000.0) / $iterations;

    printf("BENCHMARK: %d iterations of data_1000.flow, avg %.4f ms/call (parseFlowBinary, no JSON)\n", $iterations, $avgBinaryMs);
    check('benchmark average under generous sanity bound (50ms)', $avgBinaryMs < 50.0, sprintf('%.4fms', $avgBinaryMs));
}

echo "\n$passed passed, $failures failed\n";
exit($failures > 0 ? 1 : 0);
