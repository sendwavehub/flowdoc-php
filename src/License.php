<?php

declare(strict_types=1);

namespace Flowdoc;

/**
 * License-validation soft gate + explicit activation call for FlowDoc's
 * freemium licensing (see CLAUDE.md's "Update" section and
 * backend/src/routes/licenses.js) -- the PHP counterpart to
 * bindings/python's license_status()/activate_license() and
 * bindings/nodejs's licenseStatus()/activateLicense().
 *
 * Soft gate, not a hard one: no Pro-exclusive capability exists yet for a
 * hard gate to protect, and there is no live license server to hard-depend
 * on either. So NativeParser::parseFlow() etc. never consult this class's
 * state and never block on it -- if FLOWDOC_LICENSE_KEY isn't set (true
 * for every user of this package today), status() does no network
 * activity at all, and this class does nothing unless a caller explicitly
 * invokes one of its two public methods.
 *
 * ## Why status() is explicit-only here, unlike Python/Node
 *
 * bindings/python fires its background validation thread at module
 * import; bindings/nodejs fires its (non-blocking) request at
 * require(). Neither maps cleanly onto PHP:
 *
 *   - PHP has no comparable "on load" hook. Composer's PSR-4 autoloading
 *     only registers a namespace-to-directory mapping -- it does not
 *     execute anything until a caller actually references a method on the
 *     class, and by construction that first reference already *is* an
 *     explicit call to something. There's no equivalent of "top-level
 *     module code that runs once, whether or not anyone asked for
 *     licensing."
 *   - A typical PHP process (a CLI script, a php-fpm worker handling one
 *     HTTP request) is short-lived and may never touch licensing at all.
 *     Firing a real network call as a side effect of the first reference
 *     to this class -- unconditionally, for every consumer -- would be
 *     worse than Python/Node's background thread/promise, which at least
 *     never blocks anything: here it would mean paying real network
 *     latency (or the 3s timeout, if the server is unreachable) inside
 *     what looks like a harmless class reference.
 *
 * So status() only ever runs when a caller explicitly calls it (e.g. once
 * during app bootstrap, or from a health check) -- documenting the
 * decision here rather than silently deviating from the other bindings'
 * shape. Its result is still cached for the remainder of the process (a
 * static property), so a long-running worker (RoadRunner, Swoole, a queue
 * consumer) that calls it repeatedly only pays the network cost once,
 * matching Python/Node's own per-process caching -- just triggered by the
 * caller instead of automatically.
 */
final class License
{
    private const LICENSE_KEY_ENV = 'FLOWDOC_LICENSE_KEY';
    private const LICENSE_SERVER_ENV = 'FLOWDOC_LICENSE_SERVER';
    private const VALIDATE_TIMEOUT_SECONDS = 3;
    private const ACTIVATE_TIMEOUT_SECONDS = 5;

    private const ACTIVATION_RESULT_FIELDS = [
        'message', 'tier', 'seats', 'expiresAt', 'customerId', 'signedLicenseArtifact',
    ];

    /** @var array{checked: bool, valid: bool|null, error: string|null}|null */
    private static ?array $statusCache = null;

    private function __construct()
    {
        // Static-only utility class.
    }

    /**
     * Returns this process's cached license-validation result:
     * ['checked' => bool, 'valid' => true/false/null, 'error' => string|null].
     *
     * `valid` is null when there was nothing to check (no
     * FLOWDOC_LICENSE_KEY set) or nothing could be checked (no
     * FLOWDOC_LICENSE_SERVER, or the server was unreachable) -- only ever
     * true/false after a real response from the backend's
     * POST /api/licenses/validate. Never throws.
     *
     * The first call performs the real check (a synchronous HTTP POST,
     * see the class doc comment for why this is explicit rather than
     * automatic); every subsequent call in the same process returns the
     * cached result. Call resetStatusCache() to force a re-check.
     *
     * @return array{checked: bool, valid: bool|null, error: string|null}
     */
    public static function status(): array
    {
        if (self::$statusCache === null) {
            self::$statusCache = self::validateOnce();
        }

        return self::$statusCache;
    }

    /**
     * Clears the per-process status cache populated by status(). Normal
     * callers never need this -- it exists for tests, and for any
     * long-running worker that wants to force a fresh check.
     */
    public static function resetStatusCache(): void
    {
        self::$statusCache = null;
    }

    /**
     * @return array{checked: bool, valid: bool|null, error: string|null}
     */
    private static function validateOnce(): array
    {
        $key = getenv(self::LICENSE_KEY_ENV);
        if ($key === false || $key === '') {
            return ['checked' => true, 'valid' => null, 'error' => null];
        }

        $server = getenv(self::LICENSE_SERVER_ENV);
        if ($server === false || $server === '') {
            return [
                'checked' => true,
                'valid' => null,
                'error' => self::LICENSE_KEY_ENV . ' is set but ' . self::LICENSE_SERVER_ENV
                    . ' is not -- skipping validation',
            ];
        }

        $url = rtrim($server, '/') . '/api/licenses/validate';
        $body = (string) json_encode(['key' => $key, 'language' => 'php']);

        $response = self::httpPostJson($url, $body, self::VALIDATE_TIMEOUT_SECONDS);
        if ($response['error'] !== null) {
            $error = 'license validation unreachable: ' . $response['error'];
            self::warn($error);
            return ['checked' => true, 'valid' => null, 'error' => $error];
        }

        $result = json_decode((string) $response['raw'], true);
        if (!is_array($result)) {
            $error = 'license validation unreachable: response was not valid JSON';
            self::warn($error);
            return ['checked' => true, 'valid' => null, 'error' => $error];
        }

        $valid = (bool) ($result['valid'] ?? false);
        $error = $valid ? null : (string) ($result['error'] ?? 'invalid license key');
        if (!$valid) {
            self::warn('license invalid: ' . $error);
        }

        return ['checked' => true, 'valid' => $valid, 'error' => $error];
    }

    /**
     * Calls POST {FLOWDOC_LICENSE_SERVER}/licenses/{FLOWDOC_LICENSE_KEY}/activate
     * once, synchronously.
     *
     * Unlike status()'s soft check, this is a mutating call (flips the
     * license to "Activated" server-side) and only ever runs when a
     * caller explicitly invokes it -- e.g. once on first run/install, per
     * the license-admin activation contract. It never fires on its own.
     *
     * Returns ['success' => bool, 'error' => string|null, 'message',
     * 'tier', 'seats', 'expiresAt', 'customerId', 'signedLicenseArtifact']
     * -- the last five are null whenever 'success' is false. Never throws.
     *
     * @param array<string, mixed>|null $metadata
     * @return array{success: bool, error: string|null, message: mixed, tier: mixed,
     *               seats: mixed, expiresAt: mixed, customerId: mixed, signedLicenseArtifact: mixed}
     */
    public static function activate(string $activatedBy, ?string $activationIp = null, ?array $metadata = null): array
    {
        if ($activatedBy === '') {
            return self::activationFailure('activated_by is required');
        }

        $key = getenv(self::LICENSE_KEY_ENV);
        if ($key === false || $key === '') {
            return self::activationFailure(self::LICENSE_KEY_ENV . ' is not set');
        }

        $server = getenv(self::LICENSE_SERVER_ENV);
        if ($server === false || $server === '') {
            return self::activationFailure(self::LICENSE_SERVER_ENV . ' is not set');
        }

        $bodyData = ['activatedBy' => $activatedBy];
        if ($activationIp !== null) {
            $bodyData['activationIp'] = $activationIp;
        }
        if ($metadata !== null) {
            $bodyData['metadata'] = $metadata;
        }
        $body = (string) json_encode($bodyData);

        // Single /api/ (not validate's doubled /api/api/): FLOWDOC_LICENSE_SERVER
        // already carries one /api/ path segment (see validateOnce() above),
        // so appending /licenses/<key>/activate directly -- no extra /api/ --
        // lands on the documented single-/api/ activate route.
        $url = rtrim($server, '/') . '/licenses/' . rawurlencode($key) . '/activate';

        $response = self::httpPostJson($url, $body, self::ACTIVATE_TIMEOUT_SECONDS);
        if ($response['error'] !== null) {
            $error = 'license activation unreachable: ' . $response['error'];
            self::warn($error);
            return self::activationFailure($error);
        }

        $result = json_decode((string) $response['raw'], true);
        if (!is_array($result)) {
            $error = 'activation response was not valid JSON';
            self::warn($error);
            return self::activationFailure($error);
        }

        $status = $response['status'] ?? 0;
        if ($status >= 200 && $status < 300 && ($result['success'] ?? false)) {
            $activation = ['success' => true, 'error' => null];
            foreach (self::ACTIVATION_RESULT_FIELDS as $field) {
                $activation[$field] = $result[$field] ?? null;
            }

            return $activation;
        }

        $error = self::activationErrorMessage($result, "activation failed: HTTP {$status}");
        self::warn($error);

        return self::activationFailure($error);
    }

    /**
     * @return array{success: bool, error: string, message: null, tier: null,
     *               seats: null, expiresAt: null, customerId: null, signedLicenseArtifact: null}
     */
    private static function activationFailure(string $error): array
    {
        $result = ['success' => false, 'error' => $error];
        foreach (self::ACTIVATION_RESULT_FIELDS as $field) {
            $result[$field] = null;
        }

        /** @var array{success: bool, error: string, message: null, tier: null, seats: null, expiresAt: null, customerId: null, signedLicenseArtifact: null} $result */
        return $result;
    }

    /**
     * Extracts a human-readable error from an activation response body.
     * The server's `error` field may be a plain string OR an object like
     * {"code": ..., "message": ...} -- handle both, falling back to
     * `message`, falling back to $fallback.
     *
     * @param array<string, mixed> $result
     */
    private static function activationErrorMessage(array $result, string $fallback): string
    {
        $error = $result['error'] ?? null;
        if (is_array($error)) {
            return (string) ($error['message'] ?? $fallback);
        }
        if (is_string($error) && $error !== '') {
            return $error;
        }

        return (string) ($result['message'] ?? $fallback);
    }

    /**
     * Plain file_get_contents()-over-a-stream-context POST -- no curl
     * dependency, matching composer.json's existing requirements
     * (ext-ffi only; not ext-curl). 'ignore_errors' => true is what makes
     * a non-2xx response's body still readable instead of file_get_contents
     * simply returning false for it; a genuine connection failure (refused,
     * DNS, timeout) still returns false, which is treated as an error below.
     *
     * @return array{status: int|null, raw: string|null, error: string|null}
     */
    private static function httpPostJson(string $url, string $jsonBody, int $timeoutSeconds): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $jsonBody,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        error_clear_last();
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            $lastError = error_get_last();
            $message = $lastError['message'] ?? 'connection failed';

            return ['status' => null, 'raw' => null, 'error' => self::cleanWarning($message)];
        }

        $status = self::extractStatusCode($http_response_header ?? []);

        return ['status' => $status, 'raw' => $raw, 'error' => null];
    }

    /**
     * @param array<int, string> $headers
     */
    private static function extractStatusCode(array $headers): ?int
    {
        if (!isset($headers[0])) {
            return null;
        }
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headers[0], $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * error_get_last()'s message for a failed stream-wrapper call is
     * prefixed with "file_get_contents(...): " -- strip that PHP-ism so
     * the reported error reads like the other bindings' (e.g.
     * "Connection refused" rather than
     * "file_get_contents(http://...): Failed to open stream: Connection refused").
     */
    private static function cleanWarning(string $message): string
    {
        $stripped = preg_replace('/^file_get_contents\([^)]*\):\s*/', '', $message);

        return $stripped ?? $message;
    }

    private static function warn(string $message): void
    {
        error_log('flowdoc: ' . $message);
    }
}
