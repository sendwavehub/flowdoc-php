<?php

declare(strict_types=1);

/**
 * Router script for PHP's built-in web server (`php -S`), used by
 * StubLicenseServer as a real local HTTP stub for FLOWDOC_LICENSE_SERVER
 * in LicenseTest -- a genuine socket round-trip, not a mocked HTTP call.
 *
 * Entirely configured via environment variables set on this process by
 * StubLicenseServer::start() (see its doc comment for the full contract):
 *
 *   STUB_RESPONSE_BODY   raw response body to send back (already-encoded JSON)
 *   STUB_RESPONSE_STATUS HTTP status code to send back (default 200)
 *   STUB_EXPECTED_PATH   if set, only this exact request path gets the
 *                        configured response; anything else gets a 404
 *                        whose body names the path actually received --
 *                        this is what lets a test prove the client hit
 *                        the exact URL it was supposed to.
 *   STUB_CAPTURE_FILE    if set, every request's path + JSON-decoded body
 *                        is appended as one JSON line, for the test
 *                        process to read back via StubLicenseServer::lastRequest().
 */

$capturePath = getenv('STUB_CAPTURE_FILE');
if ($capturePath !== false) {
    $rawBody = file_get_contents('php://input');
    $entry = [
        'path' => $_SERVER['REQUEST_URI'] ?? '',
        'body' => ($rawBody !== false && $rawBody !== '') ? json_decode($rawBody, true) : null,
    ];
    file_put_contents($capturePath, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
}

$expectedPath = getenv('STUB_EXPECTED_PATH');
if ($expectedPath !== false && ($_SERVER['REQUEST_URI'] ?? '') !== $expectedPath) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => 'wrong path: got ' . ($_SERVER['REQUEST_URI'] ?? '') . ', expected ' . $expectedPath,
    ]);
    return;
}

$status = (int) (getenv('STUB_RESPONSE_STATUS') ?: 200);
http_response_code($status);
header('Content-Type: application/json');
$body = getenv('STUB_RESPONSE_BODY');
echo $body !== false ? $body : '{}';
