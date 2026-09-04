<?php

declare(strict_types=1);

namespace Flowdoc\Tests;

use Flowdoc\License;
use Flowdoc\NativeParser;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/support/StubLicenseServer.php';

/**
 * Tests for the license-validation soft gate (License::status()) and the
 * explicit license-activation call (License::activate()) -- the PHP
 * counterpart to bindings/python's license_status()/activate_license() and
 * bindings/nodejs's licenseStatus()/activateLicense(). See License.php's
 * class doc comment for why status() is explicit-only here rather than
 * firing automatically the way the Python/Node versions do.
 *
 * Every server-talking test runs against a real local HTTP server
 * (PHP's built-in server via StubLicenseServer, tests/support/) -- no
 * mocked network call anywhere in this file.
 */
final class LicenseTest extends TestCase
{
    private ?string $originalKey;
    private ?string $originalServer;

    protected function setUp(): void
    {
        $key = getenv('FLOWDOC_LICENSE_KEY');
        $server = getenv('FLOWDOC_LICENSE_SERVER');
        $this->originalKey = $key === false ? null : $key;
        $this->originalServer = $server === false ? null : $server;

        putenv('FLOWDOC_LICENSE_KEY');
        putenv('FLOWDOC_LICENSE_SERVER');
        License::resetStatusCache();
    }

    protected function tearDown(): void
    {
        putenv($this->originalKey === null ? 'FLOWDOC_LICENSE_KEY' : 'FLOWDOC_LICENSE_KEY=' . $this->originalKey);
        putenv($this->originalServer === null ? 'FLOWDOC_LICENSE_SERVER' : 'FLOWDOC_LICENSE_SERVER=' . $this->originalServer);
        License::resetStatusCache();
    }

    public function testStatusIsNoopWithoutAKey(): void
    {
        $status = License::status();

        self::assertSame(['checked' => true, 'valid' => null, 'error' => null], $status);
    }

    public function testStatusSkipsValidationWithoutAServer(): void
    {
        putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');

        $status = License::status();

        self::assertTrue($status['checked']);
        self::assertNull($status['valid']);
        self::assertStringContainsString('FLOWDOC_LICENSE_SERVER', $status['error']);
    }

    public function testStatusValidKey(): void
    {
        $server = StubLicenseServer::start(json_encode(['valid' => true, 'expires_in_days' => 30]));
        try {
            putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');
            putenv('FLOWDOC_LICENSE_SERVER=' . $server->url);

            $status = License::status();

            self::assertSame(['checked' => true, 'valid' => true, 'error' => null], $status);
        } finally {
            $server->stop();
        }
    }

    public function testStatusInvalidKey(): void
    {
        $server = StubLicenseServer::start(json_encode(['valid' => false, 'error' => 'License expired']));
        try {
            putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');
            putenv('FLOWDOC_LICENSE_SERVER=' . $server->url);

            $status = License::status();

            self::assertSame(['checked' => true, 'valid' => false, 'error' => 'License expired'], $status);
        } finally {
            $server->stop();
        }
    }

    public function testStatusUnreachableServerNeverThrows(): void
    {
        putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');
        putenv('FLOWDOC_LICENSE_SERVER=http://127.0.0.1:1');

        $status = License::status();

        self::assertTrue($status['checked']);
        self::assertNull($status['valid']);
        self::assertStringContainsString('license validation unreachable', $status['error']);
    }

    public function testValidatePreservesAnExistingPathSegmentInTheServerEnv(): void
    {
        // A reverse proxy in front of the real backend may only forward
        // under a path prefix -- FLOWDOC_LICENSE_SERVER's existing path
        // must be preserved, not dropped, when /api/licenses/validate is
        // appended.
        $server = StubLicenseServer::start(
            json_encode(['valid' => true]),
            200,
            '/custom-prefix/api/licenses/validate'
        );
        try {
            putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');
            putenv('FLOWDOC_LICENSE_SERVER=' . $server->url . '/custom-prefix');

            $status = License::status();

            self::assertSame(['checked' => true, 'valid' => true, 'error' => null], $status);
        } finally {
            $server->stop();
        }
    }

    public function testStatusIsCachedForTheRestOfTheProcess(): void
    {
        // License::status() is explicit-only (no automatic firing) but its
        // result is still cached per process once called -- a second call
        // must return the identical cached array without needing another
        // live server (this one just proves repeated calls agree; a real
        // "only one HTTP call happened" assertion would need a request
        // counter, which this simple stub doesn't track).
        $server = StubLicenseServer::start(json_encode(['valid' => true]));
        try {
            putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');
            putenv('FLOWDOC_LICENSE_SERVER=' . $server->url);

            $first = License::status();
            $second = License::status();

            self::assertSame($first, $second);
        } finally {
            $server->stop();
        }
    }

    public function testParseFlowIsUnaffectedByLicenseStatus(): void
    {
        // The whole point of the soft gate: parsing behaves identically
        // regardless of license state, even an invalid one.
        $server = StubLicenseServer::start(json_encode(['valid' => false, 'error' => 'License expired']));
        try {
            putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');
            putenv('FLOWDOC_LICENSE_SERVER=' . $server->url);
            License::status();

            $result = NativeParser::parseFlow("Record\n  id: 1\n");

            self::assertSame([['id' => '1']], $result);
        } finally {
            $server->stop();
        }
    }

    public function testActivateRequiresActivatedBy(): void
    {
        putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');
        putenv('FLOWDOC_LICENSE_SERVER=http://127.0.0.1:1');

        $result = License::activate('');

        self::assertFalse($result['success']);
        self::assertStringContainsString('activated_by', $result['error']);
    }

    public function testActivateRequiresAKey(): void
    {
        putenv('FLOWDOC_LICENSE_SERVER=http://127.0.0.1:1');

        $result = License::activate('install-script');

        self::assertFalse($result['success']);
        self::assertStringContainsString('FLOWDOC_LICENSE_KEY', $result['error']);
    }

    public function testActivateRequiresAServer(): void
    {
        putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');

        $result = License::activate('install-script');

        self::assertFalse($result['success']);
        self::assertStringContainsString('FLOWDOC_LICENSE_SERVER', $result['error']);
    }

    public function testActivateSuccessHitsTheDocumentedPathAndBody(): void
    {
        $server = StubLicenseServer::start(
            json_encode([
                'success' => true,
                'message' => 'Activated',
                'signedLicenseArtifact' => 'YmFzZTY0',
                'tier' => 'pro',
                'seats' => 5,
                'expiresAt' => '2027-01-01T00:00:00Z',
                'customerId' => 'cust_123',
            ]),
            200,
            null,
            true
        );
        try {
            putenv('FLOWDOC_LICENSE_KEY=FLOWDOC-1788529960037-7BA65002');
            putenv('FLOWDOC_LICENSE_SERVER=' . $server->url);

            $result = License::activate('install-script', '127.0.0.1', ['os' => 'test']);

            self::assertTrue($result['success']);
            self::assertNull($result['error']);
            self::assertSame('YmFzZTY0', $result['signedLicenseArtifact']);
            self::assertSame('pro', $result['tier']);
            self::assertSame(5, $result['seats']);
            self::assertSame('2027-01-01T00:00:00Z', $result['expiresAt']);
            self::assertSame('cust_123', $result['customerId']);

            [$path, $body] = $server->lastRequest();
            self::assertSame('/licenses/FLOWDOC-1788529960037-7BA65002/activate', $path);
            self::assertSame(
                ['activatedBy' => 'install-script', 'activationIp' => '127.0.0.1', 'metadata' => ['os' => 'test']],
                $body
            );
        } finally {
            $server->stop();
        }
    }

    public function testActivateOmitsOptionalFieldsWhenNotProvided(): void
    {
        $server = StubLicenseServer::start(json_encode(['success' => true]), 200, null, true);
        try {
            putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');
            putenv('FLOWDOC_LICENSE_SERVER=' . $server->url);

            License::activate('install-script');

            [, $body] = $server->lastRequest();
            self::assertSame(['activatedBy' => 'install-script'], $body);
        } finally {
            $server->stop();
        }
    }

    public function testActivateReportsAServerRejectionWithAPlainStringError(): void
    {
        $server = StubLicenseServer::start(json_encode(['error' => 'already activated']), 400);
        try {
            putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');
            putenv('FLOWDOC_LICENSE_SERVER=' . $server->url);

            $result = License::activate('install-script');

            self::assertFalse($result['success']);
            self::assertSame('already activated', $result['error']);
            self::assertNull($result['tier']);
        } finally {
            $server->stop();
        }
    }

    public function testActivateReportsAServerRejectionWithAnObjectError(): void
    {
        $server = StubLicenseServer::start(
            json_encode(['error' => ['code' => 'ALREADY_ACTIVE', 'message' => 'license already activated']]),
            409
        );
        try {
            putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');
            putenv('FLOWDOC_LICENSE_SERVER=' . $server->url);

            $result = License::activate('install-script');

            self::assertFalse($result['success']);
            self::assertSame('license already activated', $result['error']);
        } finally {
            $server->stop();
        }
    }

    public function testActivateFallsBackToAGenericMessageWithNoErrorOrMessageField(): void
    {
        $server = StubLicenseServer::start(json_encode(['success' => false]), 500);
        try {
            putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');
            putenv('FLOWDOC_LICENSE_SERVER=' . $server->url);

            $result = License::activate('install-script');

            self::assertFalse($result['success']);
            self::assertStringContainsString('HTTP 500', $result['error']);
        } finally {
            $server->stop();
        }
    }

    public function testActivateUnreachableServerNeverThrows(): void
    {
        putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');
        putenv('FLOWDOC_LICENSE_SERVER=http://127.0.0.1:1');

        $result = License::activate('install-script');

        self::assertFalse($result['success']);
        self::assertStringContainsString('license activation unreachable', $result['error']);
    }

    public function testActivatePreservesAnExistingPathSegmentInTheServerEnv(): void
    {
        $server = StubLicenseServer::start(
            json_encode(['success' => true]),
            200,
            '/custom-prefix/licenses/flowdoc_testkey/activate',
            true
        );
        try {
            putenv('FLOWDOC_LICENSE_KEY=flowdoc_testkey');
            putenv('FLOWDOC_LICENSE_SERVER=' . $server->url . '/custom-prefix');

            $result = License::activate('install-script');

            self::assertTrue($result['success']);
            [$path, ] = $server->lastRequest();
            self::assertSame('/custom-prefix/licenses/flowdoc_testkey/activate', $path);
        } finally {
            $server->stop();
        }
    }
}
