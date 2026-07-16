<?php

namespace coyshdigital\craftanalytics\console\controllers;

use coyshdigital\craftanalytics\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use yii\console\ExitCode;

/**
 * Installs the local geolocation database.
 *
 * Deliberately a command an operator runs, never something the plugin does on
 * its own: the plugin makes no outbound network calls at runtime (C7), and
 * downloading a 100 MB file because a setting got toggled is exactly the kind
 * of surprise that rule exists to prevent.
 *
 *     craft-analytics/geo/install --from=/path/to/dbip-city-lite.mmdb
 *     craft-analytics/geo/install --url=https://download.db-ip.com/free/...
 *     craft-analytics/geo/status
 */
class GeoController extends Controller
{
    /** A local .mmdb file to install. */
    public ?string $from = null;

    /**
     * A URL to fetch the database from. Explicit on purpose: you are asking
     * for this network call, we are not making it on our own initiative.
     */
    public ?string $url = null;

    public function options($actionID): array
    {
        return match ($actionID) {
            'install' => array_merge(parent::options($actionID), ['from', 'url']),
            default => parent::options($actionID),
        };
    }

    /**
     * Install the geolocation database.
     */
    public function actionInstall(): int
    {
        if ($this->from === null && $this->url === null) {
            $this->stderr("Specify --from=<path> or --url=<url>.\n\n", Console::FG_RED);
            $this->printSources();

            return ExitCode::USAGE;
        }

        $destination = Plugin::getInstance()->getGeo()->path();
        FileHelper::createDirectory(dirname($destination));

        $bytes = $this->from !== null
            ? $this->copyLocal($this->from, $destination)
            : $this->download((string)$this->url, $destination);

        if ($bytes === null) {
            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(sprintf("Installed %s (%s).\n", $destination, self::formatBytes($bytes)), Console::FG_GREEN);

        return $this->actionStatus();
    }

    /**
     * Show what geolocation database is installed.
     */
    public function actionStatus(): int
    {
        $geo = Plugin::getInstance()->getGeo();
        $info = $geo->databaseInfo();

        if (!$info['installed']) {
            $this->stdout("No geolocation database installed.\n", Console::FG_YELLOW);
            $this->stdout("Expected at: {$info['path']}\n\n");
            $this->printSources();

            return ExitCode::OK;
        }

        $this->stdout("Geolocation database\n", Console::FG_GREEN);
        $this->stdout('  path:  ' . $info['path'] . "\n");
        $this->stdout('  type:  ' . ($info['type'] ?? 'unreadable - the file may be corrupt') . "\n");
        $this->stdout('  built: ' . ($info['buildDate'] ?? 'unknown') . "\n");
        $this->stdout('  size:  ' . self::formatBytes($info['sizeBytes']) . "\n");

        $attribution = $geo->attributionNotice();

        if ($attribution !== null) {
            $this->stdout("\n" . $attribution . "\n", Console::FG_GREY);
            $this->stdout("This attribution is required by the licence and is shown in the CP.\n", Console::FG_GREY);
        }

        if (!Plugin::getInstance()->getSettings()->enableGeo) {
            $this->stdout("\nGeo is installed but `enableGeo` is off, so nothing is being resolved.\n", Console::FG_YELLOW);
        }

        return ExitCode::OK;
    }

    private function copyLocal(string $source, string $destination): ?int
    {
        if (!is_file($source)) {
            $this->stderr("No such file: $source\n", Console::FG_RED);

            return null;
        }

        if (!copy($source, $destination)) {
            $this->stderr("Could not copy to $destination\n", Console::FG_RED);

            return null;
        }

        return (int)filesize($destination);
    }

    private function download(string $url, string $destination): ?int
    {
        if (!str_starts_with($url, 'https://')) {
            $this->stderr("Only https:// URLs are accepted.\n", Console::FG_RED);

            return null;
        }

        $this->stdout("Downloading $url …\n");
        $temp = $destination . '.download';
        $source = @fopen($url, 'rb');

        if ($source === false) {
            $this->stderr("Could not open the URL.\n", Console::FG_RED);

            return null;
        }

        $target = fopen($temp, 'wb');

        if ($target === false) {
            fclose($source);

            return null;
        }

        stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        // Gzipped is how DB-IP ships it.
        if (self::isGzip($temp)) {
            $this->stdout("Decompressing …\n");

            if (!self::gunzip($temp, $destination)) {
                $this->stderr("Could not decompress the download.\n", Console::FG_RED);
                @unlink($temp);

                return null;
            }

            @unlink($temp);
        } else {
            rename($temp, $destination);
        }

        return (int)filesize($destination);
    }

    private static function isGzip(string $path): bool
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $magic = fread($handle, 2);
        fclose($handle);

        return $magic === "\x1f\x8b";
    }

    private static function gunzip(string $source, string $destination): bool
    {
        $in = gzopen($source, 'rb');
        $out = fopen($destination, 'wb');

        if ($in === false || $out === false) {
            return false;
        }

        while (!gzeof($in)) {
            fwrite($out, (string)gzread($in, 262144));
        }

        gzclose($in);
        fclose($out);

        return true;
    }

    private function printSources(): void
    {
        $this->stdout("Where to get one:\n\n");
        $this->stdout("  DB-IP Lite (recommended) - free, CC BY 4.0, redistributable:\n");
        $this->stdout("    https://db-ip.com/db/download/ip-to-city-lite\n");
        $this->stdout("    Attribution to DB-IP is required, and the CP shows it for you.\n\n");
        $this->stdout("  MaxMind GeoLite2 - free with an account, under MaxMind's own licence:\n");
        $this->stdout("    https://dev.maxmind.com/geoip/geolite2-free-geolocation-data\n");
        $this->stdout("    We can read the file, but we can't ship it for you.\n\n");
        $this->stdout("Both are monthly releases. Re-run this command to update; nothing\n");
        $this->stdout("downloads on its own.\n", Console::FG_GREY);
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1024) . ' KB';
    }
}
