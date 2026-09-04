<?php

declare(strict_types=1);

namespace Flowdoc\Tests;

use RuntimeException;

/**
 * Real local HTTP server (PHP's built-in server, `php -S`, run as a
 * subprocess) used by LicenseTest as a stub for FLOWDOC_LICENSE_SERVER --
 * a genuine round trip over a real socket, not a mocked HTTP call, per
 * this repo's tdd-workflow skill ("a test that mocks the ... call and
 * asserts the mock was invoked correctly tests the mock, not the
 * binding" -- the same principle applies to any outbound network call,
 * not just FFI).
 *
 * Each instance runs support/license_stub_router.php, configured entirely
 * through environment variables passed to that subprocess: a fixed
 * response body/status, an optional exact path to enforce (anything else
 * gets a 404 naming the path actually received), and an optional capture
 * file the router appends one JSON line to per request, read back here via
 * lastRequest().
 */
final class StubLicenseServer
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    public readonly string $url;

    private ?string $captureFile;

    /**
     * @param resource $process
     * @param array<int, resource> $pipes
     */
    private function __construct($process, array $pipes, string $url, ?string $captureFile)
    {
        $this->process = $process;
        $this->pipes = $pipes;
        $this->url = $url;
        $this->captureFile = $captureFile;
    }

    public static function start(
        string $responseBody,
        int $status = 200,
        ?string $expectedPath = null,
        bool $capture = false
    ): self {
        $port = self::findFreePort();
        $captureFile = $capture ? tempnam(sys_get_temp_dir(), 'flowdoc-license-capture-') : null;

        $env = getenv();
        $env['STUB_RESPONSE_BODY'] = $responseBody;
        $env['STUB_RESPONSE_STATUS'] = (string) $status;
        if ($expectedPath !== null) {
            $env['STUB_EXPECTED_PATH'] = $expectedPath;
        }
        if ($captureFile !== null) {
            $env['STUB_CAPTURE_FILE'] = $captureFile;
        }

        $router = __DIR__ . '/license_stub_router.php';
        $cmd = [PHP_BINARY, '-S', "127.0.0.1:$port", $router];

        $pipes = [];
        $process = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );

        if ($process === false) {
            throw new RuntimeException('failed to start the stub license server');
        }

        $url = "http://127.0.0.1:$port";
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                throw new RuntimeException(
                    'stub license server exited early: ' . stream_get_contents($pipes[2])
                );
            }

            $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($conn !== false) {
                fclose($conn);
                return new self($process, $pipes, $url, $captureFile);
            }
            usleep(20000);
        }

        proc_terminate($process);
        throw new RuntimeException('stub license server did not start listening in time');
    }

    /**
     * Returns [path, decoded-JSON-body-or-null] of the most recently
     * captured request. Requires capture: true at start().
     *
     * @return array{0: string, 1: mixed}
     */
    public function lastRequest(): array
    {
        if ($this->captureFile === null) {
            throw new RuntimeException('this stub server was not started with capture: true');
        }

        // The router appends one line per request; a request can lag
        // slightly behind file_get_contents() returning from the client
        // side, so poll briefly instead of assuming the write already landed.
        $deadline = microtime(true) + 2.0;
        $lines = [];
        while (microtime(true) < $deadline) {
            $contents = (string) file_get_contents($this->captureFile);
            $lines = array_values(array_filter(explode("\n", $contents), static fn ($l) => $l !== ''));
            if ($lines !== []) {
                break;
            }
            usleep(10000);
        }

        if ($lines === []) {
            throw new RuntimeException('no request was captured by the stub license server');
        }

        $entry = json_decode(end($lines), true);
        return [$entry['path'], $entry['body']];
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if ($this->captureFile !== null && is_file($this->captureFile)) {
            unlink($this->captureFile);
        }
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException("could not find a free port: $errstr");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
