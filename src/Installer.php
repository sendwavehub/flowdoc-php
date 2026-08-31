<?php

declare(strict_types=1);

namespace Flowdoc;

use Composer\InstalledVersions;
use Composer\Script\Event;

/**
 * Composer post-install-cmd/post-update-cmd hook (wired up in
 * composer.json) that downloads a prebuilt native library for the current
 * platform, verifies it against native-checksums.json before trusting it,
 * and writes it to BUNDLED_NATIVE_DIR -- the same directory
 * NativeParser::libraryPath() already checks.
 *
 * Deliberately never throws: a consumer's `composer install` must not fail
 * just because this optional convenience step didn't work (no network, an
 * unpublished platform, whatever). NativeParser has three other fallbacks
 * (env var, system paths, monorepo build output) -- this is a nice-to-have
 * on top of those, not the only path to a working install.
 */
final class Installer
{
    private const CHECKSUMS_FILE = __DIR__ . '/../native-checksums.json';
    private const BUNDLED_NATIVE_DIR = __DIR__ . '/../native/';

    private function __construct()
    {
        // Static-only utility class.
    }

    public static function postInstall(Event $event): void
    {
        $io = $event->getIO();

        if (getenv('FLOWDOC_SKIP_NATIVE_DOWNLOAD') !== false) {
            $io->write('<info>flowdoc:</info> FLOWDOC_SKIP_NATIVE_DOWNLOAD set, skipping native library download.');
            return;
        }

        try {
            self::run($io);
        } catch (\Throwable $e) {
            // See class docblock: this step is optional. Warn, don't fail
            // the consumer's install over it.
            $io->writeError('<warning>flowdoc:</warning> native library download skipped: ' . $e->getMessage());
        }
    }

    /**
     * @param \Composer\IO\IOInterface $io
     */
    private static function run($io): void
    {
        $platformDir = Platform::dirName();
        if ($platformDir === null) {
            $io->write('<info>flowdoc:</info> no cargo build target for ' . PHP_OS_FAMILY . ', skipping native library download.');
            return;
        }

        $version = InstalledVersions::getPrettyVersion('sendwavehub/flowdoc');
        if ($version === null) {
            $io->write('<info>flowdoc:</info> could not determine installed version, skipping native library download.');
            return;
        }
        // Composer prefixes some pretty-versions ("v1.0.0") depending on
        // how the tag was cut, and appends SemVer build metadata
        // ("1.0.0+no-version-set") when it can't detect a real VCS
        // version (e.g. a local path/dev checkout, like this repo's own
        // test suite) -- native-checksums.json is keyed on the bare
        // "1.0.0" form in both cases.
        $version = preg_replace('/\+.*$/', '', ltrim($version, 'v'));

        $manifest = self::loadManifest();
        $entry = $manifest[$version][$platformDir] ?? null;
        if ($entry === null || !isset($entry['sha256'], $entry['url'], $entry['filename'])) {
            $io->write(
                "<info>flowdoc:</info> no published native library for $platformDir at version $version yet, "
                . 'skipping download. Set FLOWDOC_NATIVE_LIB_PATH or see README.md for other options.'
            );
            return;
        }

        $targetDir = self::BUNDLED_NATIVE_DIR . $platformDir . '/';
        $targetPath = $targetDir . $entry['filename'];
        if (is_file($targetPath) && hash_file('sha256', $targetPath) === $entry['sha256']) {
            $io->write('<info>flowdoc:</info> native library already present and verified, skipping download.');
            return;
        }

        if (!ini_get('allow_url_fopen')) {
            $io->write('<info>flowdoc:</info> allow_url_fopen is disabled, skipping native library download.');
            return;
        }

        $io->write("<info>flowdoc:</info> downloading native library for $platformDir from {$entry['url']} ...");
        $context = stream_context_create(['http' => ['timeout' => 30], 'https' => ['timeout' => 30]]);
        $data = @file_get_contents($entry['url'], false, $context);
        if ($data === false) {
            $io->writeError("<warning>flowdoc:</warning> download failed for {$entry['url']}.");
            return;
        }

        $actualHash = hash('sha256', $data);
        if (!hash_equals($entry['sha256'], $actualHash)) {
            $io->writeError(
                "<warning>flowdoc:</warning> checksum mismatch for {$entry['url']} "
                . "(expected {$entry['sha256']}, got $actualHash) -- discarding download, not writing a file."
            );
            return;
        }

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            $io->writeError("<warning>flowdoc:</warning> could not create $targetDir.");
            return;
        }

        if (@file_put_contents($targetPath, $data) === false) {
            $io->writeError("<warning>flowdoc:</warning> could not write $targetPath.");
            return;
        }

        $io->write("<info>flowdoc:</info> native library verified (sha256 match) and installed to $targetPath.");
    }

    /**
     * @return array<string, array<string, array{filename?: string, sha256?: string, url?: string}>>
     */
    private static function loadManifest(): array
    {
        if (!is_file(self::CHECKSUMS_FILE)) {
            return [];
        }

        $json = file_get_contents(self::CHECKSUMS_FILE);
        if ($json === false) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
