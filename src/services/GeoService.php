<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use MaxMind\Db\Reader;
use yii\base\Component;

/**
 * Country and region, from a local database.
 *
 * The whole point is what *doesn't* happen: no lookup service is called, no
 * address leaves the server, and the address itself is resolved and dropped
 * inside a single call frame. What is stored is a two-letter country code and
 * a region name — the same detail a weather site has, and nothing that
 * identifies anybody (C5, C7).
 *
 * The database is DB-IP Lite (CC BY 4.0), which is redistributable with
 * attribution, and is read with maxmind-db/reader (Apache-2.0). A MaxMind
 * GeoLite2 file works too if a site already has one under their licence —
 * bring your own; we won't ship it.
 *
 * Nothing here ever downloads anything on its own. The console command exists
 * so that fetching the database is a decision an operator makes (C7).
 */
class GeoService extends Component
{
    public ?Settings $settings = null;

    /** Overridable for tests; defaults to @storage/craft-analytics/geo.mmdb. */
    public ?string $databasePath = null;

    private ?Reader $reader = null;
    private bool $readerAttempted = false;

    /** Edition override; defaults to the plugin's own. Set in tests. */
    public ?bool $isPro = null;

    public function isAvailable(): bool
    {
        return $this->settings()->enableGeo
            && ($this->isPro ??= Plugin::getInstance()->is(Plugin::EDITION_PRO))
            && is_file($this->path());
    }

    /**
     * Resolves an address.
     *
     * The address is a parameter and nothing else: it is not stored, logged,
     * or returned. Only the answer comes back.
     *
     * @return array{country: string, region: string}|null
     */
    public function resolve(string $ip): ?array
    {
        if ($ip === '' || !$this->isAvailable()) {
            return null;
        }

        $reader = $this->reader();

        if ($reader === null) {
            return null;
        }

        try {
            $record = $reader->get($ip);
        } catch (\Throwable $e) {
            // An unroutable or malformed address is ordinary, not exceptional.
            return null;
        }

        if (!is_array($record)) {
            return null;
        }

        $country = self::extractCountry($record);

        if ($country === null) {
            return null;
        }

        return [
            'country' => $country,
            'region' => self::extractRegion($record) ?? '',
        ];
    }

    public function path(): string
    {
        return $this->databasePath ??= Craft::$app->getPath()->getStoragePath()
            . DIRECTORY_SEPARATOR . 'craft-analytics'
            . DIRECTORY_SEPARATOR . 'geo.mmdb';
    }

    /**
     * What the CP and the attribution notice need to say about the database.
     *
     * @return array{installed: bool, path: string, sizeBytes: int, buildDate: string|null, type: string|null}
     */
    public function databaseInfo(): array
    {
        $path = $this->path();

        if (!is_file($path)) {
            return ['installed' => false, 'path' => $path, 'sizeBytes' => 0, 'buildDate' => null, 'type' => null];
        }

        $metadata = null;

        try {
            $metadata = (new Reader($path))->metadata();
        } catch (\Throwable) {
            // A corrupt file is still worth reporting as present-but-broken.
        }

        return [
            'installed' => true,
            'path' => $path,
            'sizeBytes' => (int)filesize($path),
            'buildDate' => $metadata !== null ? gmdate('Y-m-d', $metadata->buildEpoch) : null,
            'type' => $metadata?->databaseType,
        ];
    }

    /**
     * The attribution DB-IP's licence requires. Shown in the CP and in the
     * generated privacy documents when the database is in use.
     */
    public function attributionNotice(): ?string
    {
        $info = $this->databaseInfo();

        if (!$info['installed'] || !str_contains(strtolower((string)$info['type']), 'dbip')) {
            return null;
        }

        return 'IP geolocation by DB-IP (https://db-ip.com), used under CC BY 4.0.';
    }

    private function reader(): ?Reader
    {
        if ($this->readerAttempted) {
            return $this->reader;
        }

        $this->readerAttempted = true;

        try {
            $this->reader = new Reader($this->path());
        } catch (\Throwable $e) {
            Craft::warning('craft-analytics could not open the geo database: ' . $e->getMessage(), __METHOD__);
            $this->reader = null;
        }

        return $this->reader;
    }

    /**
     * @param array<string,mixed> $record
     */
    private static function extractCountry(array $record): ?string
    {
        // DB-IP and GeoLite2 share this layout; DB-IP's country-only edition
        // omits the nesting.
        $code = $record['country']['iso_code'] ?? $record['registered_country']['iso_code'] ?? null;

        if (!is_string($code) || strlen($code) !== 2) {
            return null;
        }

        return strtoupper($code);
    }

    /**
     * @param array<string,mixed> $record
     */
    private static function extractRegion(array $record): ?string
    {
        $subdivisions = $record['subdivisions'] ?? null;

        if (!is_array($subdivisions) || $subdivisions === []) {
            return null;
        }

        $name = $subdivisions[0]['names']['en'] ?? null;

        return is_string($name) ? $name : null;
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }
}
