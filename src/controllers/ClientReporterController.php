<?php

declare(strict_types=1);

namespace coyshdigital\craftanalytics\controllers;

use coyshdigital\craftanalytics\models\DateRange;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\helpers\App;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Read-only, HMAC-signed endpoints for Client Reporter.
 *
 * Mirrors the security model of Client Reporter's own connectors so the same
 * signed client verifies here: an HMAC over method, path, timestamp, nonce and
 * a hash of the (empty) body, with a short timestamp window and a one-shot
 * nonce. Everything returned is aggregate-only — the same figures the dashboard
 * shows — so connecting a site leaks nothing a visitor could be identified by.
 */
class ClientReporterController extends Controller
{
    protected array|bool|int $allowAnonymous = true;

    public $enableCsrfValidation = false;

    /**
     * A bound on breakdown lists, so a report stays small.
     */
    private const LIMIT = 10;

    /**
     * Authenticate every request by signature, timestamp window and nonce.
     *
     * @param \yii\base\Action $action
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $settings = Plugin::getInstance()->getSettings();
        $secret = (string) App::parseEnv($settings->clientReporterConnectionCode);
        $tolerance = $settings->clientReporterTolerance ?: 300;

        if ($secret === '') {
            throw new ForbiddenHttpException('Connector not configured.');
        }

        $timestamp = (string) $this->request->getHeaders()->get('X-CR-Timestamp');
        $nonce = (string) $this->request->getHeaders()->get('X-CR-Nonce');
        $signature = (string) $this->request->getHeaders()->get('X-CR-Signature');

        if ($timestamp === '' || $nonce === '' || $signature === '') {
            throw new ForbiddenHttpException('Missing signature.');
        }

        if (abs(time() - (int) $timestamp) > $tolerance) {
            throw new ForbiddenHttpException('Request timestamp out of range.');
        }

        $cache = Craft::$app->getCache();
        $nonceKey = 'cr_nonce_' . md5($nonce);
        if ($cache?->get($nonceKey)) {
            throw new ForbiddenHttpException('Nonce already used.');
        }

        $path = '/' . ltrim($this->request->getFullPath(), '/');
        $expected = $this->sign('GET', $path, $timestamp, $nonce, '', $secret);

        if (!hash_equals($expected, $signature)) {
            throw new ForbiddenHttpException('Invalid signature.');
        }

        $cache?->set($nonceKey, 1, $tolerance * 2);

        return true;
    }

    /**
     * MUST match Client Reporter's SignedConnectorClient::sign().
     */
    private function sign(string $method, string $path, string $timestamp, string $nonce, string $body, string $secret): string
    {
        $payload = implode("\n", [strtoupper($method), $path, $timestamp, $nonce, hash('sha256', $body)]);

        return hash_hmac('sha256', $payload, $secret);
    }

    /**
     * The verify handshake, mirroring Client Reporter's other connectors.
     */
    public function actionVerify(): Response
    {
        return $this->asJson([
            'ok' => true,
            'connector' => 'craft-analytics',
            'version' => Plugin::getInstance()->version,
            'craft_version' => Craft::$app->getVersion(),
        ]);
    }

    /**
     * The period report, in the shape Client Reporter's analytics layer expects.
     */
    public function actionReport(): Response
    {
        $siteId = (int) Craft::$app->getSites()->getCurrentSite()->id;
        $range = DateRange::custom(
            (string) ($this->request->getQueryParam('from') ?: date('Y-m-01')),
            (string) ($this->request->getQueryParam('to') ?: date('Y-m-t')),
        );

        $stats = Plugin::getInstance()->getStats();
        $totals = $stats->totals($siteId, $range);
        $trend = $stats->trend($siteId, $range);

        return $this->asJson([
            'provider' => 'Craft Analytics',
            'metrics' => [
                'visitors' => $totals['uniques'],
                'pageviews' => $totals['views'],
                'visits' => $totals['sessions'],
                'bounce_rate' => round($totals['bounceRate'], 2),
                'visit_duration' => (int) round($totals['avgDurationMs'] / 1000),
            ],
            'timeseries' => $this->trendSeries($trend),
            'top_pages' => array_map(static fn(array $row): array => [
                'label' => (string) $row['path'],
                'visitors' => (int) $row['views'],
                'pageviews' => (int) $row['views'],
            ], $stats->topPages($siteId, $range, self::LIMIT)),
            'sources' => array_map(static function(array $row): array {
                $host = (string) $row['host'];
                $label = $host !== '' ? $host : (string) $row['channel'];

                return ['label' => $label, 'visitors' => (int) $row['sessions']];
            }, $stats->sources($siteId, $range, self::LIMIT)),
            'devices' => array_map(static fn(array $row): array => [
                'label' => (string) $row['label'],
                'visitors' => (int) $row['sessions'],
            ], $stats->devices($siteId, $range, 'deviceType')),
            'countries' => array_map(static fn(array $row): array => [
                'label' => (string) $row['country'],
                'visitors' => (int) $row['sessions'],
            ], $stats->countries($siteId, $range, self::LIMIT)),
        ]);
    }

    /**
     * Turn the trend's parallel label/unique arrays into dated points.
     *
     * @param array{labels: string[], views: int[], uniques: int[], hourly: bool} $trend
     * @return array<int,array{date:string,value:int}>
     */
    private function trendSeries(array $trend): array
    {
        $series = [];
        foreach ($trend['labels'] as $i => $label) {
            $series[] = ['date' => (string) $label, 'value' => (int) ($trend['uniques'][$i] ?? 0)];
        }

        return $series;
    }
}
