<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\enums\DeviceType;
use donatj\UserAgent\UserAgentParser;
use yii\base\Component;

/**
 * Browser, OS and device type from a user agent.
 *
 * Runs in the drain, never on the request thread. Deliberately coarse: the
 * rollup stores browser name + major version, not the full version string —
 * "Chrome 126" is a useful dimension, "Chrome 126.0.6478.127" is 40,000 of
 * them and a cardinality problem (C2).
 */
class DeviceParser extends Component
{
    /**
     * Tablet markers, checked before mobile ones: an iPad's UA says
     * "Macintosh" and an Android tablet's says "Android", so neither is
     * classifiable from the platform alone.
     */
    private const TABLET_MARKERS = ['ipad', 'tablet', 'playbook', 'silk', 'kindle'];

    private const MOBILE_MARKERS = ['mobile', 'iphone', 'ipod', 'android', 'phone', 'blackberry', 'opera mini'];

    private ?UserAgentParser $parser = null;

    /**
     * @return array{browser: string, browserMajor: int, os: string, deviceType: DeviceType}
     */
    public function parse(string $userAgent): array
    {
        if ($userAgent === '') {
            return [
                'browser' => 'Unknown',
                'browserMajor' => 0,
                'os' => 'Unknown',
                'deviceType' => DeviceType::Unknown,
            ];
        }

        $parsed = ($this->parser ??= new UserAgentParser())->parse($userAgent);

        return [
            'browser' => $parsed->browser() ?: 'Unknown',
            'browserMajor' => self::majorVersion($parsed->browserVersion()),
            'os' => $parsed->platform() ?: 'Unknown',
            'deviceType' => self::deviceType($userAgent, $parsed->platform()),
        ];
    }

    private static function majorVersion(?string $version): int
    {
        if ($version === null || $version === '') {
            return 0;
        }

        return (int)explode('.', $version)[0];
    }

    private static function deviceType(string $userAgent, ?string $platform): DeviceType
    {
        $ua = strtolower($userAgent);

        foreach (self::TABLET_MARKERS as $marker) {
            if (str_contains($ua, $marker)) {
                return DeviceType::Tablet;
            }
        }

        foreach (self::MOBILE_MARKERS as $marker) {
            if (str_contains($ua, $marker)) {
                return DeviceType::Mobile;
            }
        }

        return $platform === null || $platform === '' ? DeviceType::Unknown : DeviceType::Desktop;
    }
}
