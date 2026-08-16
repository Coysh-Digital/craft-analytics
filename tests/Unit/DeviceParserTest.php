<?php

use coyshdigital\craftanalytics\enums\DeviceType;
use coyshdigital\craftanalytics\services\DeviceParser;

function parseUa(string $userAgent): array
{
    return (new DeviceParser())->parse($userAgent);
}

const CHROME_DESKTOP_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
    . 'Chrome/126.0.6478.127 Safari/537.36';

test('a real browser keeps its major version', function() {
    $device = parseUa(CHROME_DESKTOP_UA);

    expect($device['browser'])->toBe('Chrome')
        ->and($device['browserMajor'])->toBe(126)
        ->and($device['deviceType'])->toBe(DeviceType::Desktop);
});

test('an implausible major version is unknown, not stored', function() {
    // The exact spoof that quarantined a drain batch in production: 73469
    // overflows the SMALLINT column, and the write failed identically on
    // every retry. Anything past three digits is garbage, and garbage is
    // version 0 — never a number invented to fit.
    $device = parseUa(str_replace('Chrome/126.0.6478.127', 'Chrome/73469.0.0.0', CHROME_DESKTOP_UA));

    expect($device['browser'])->toBe('Chrome')
        ->and($device['browserMajor'])->toBe(0);
});

test('the largest plausible version still passes', function() {
    $device = parseUa(str_replace('Chrome/126.0.6478.127', 'Chrome/999.0.0.0', CHROME_DESKTOP_UA));

    expect($device['browserMajor'])->toBe(999);
});

test('an empty user agent is wholly unknown', function() {
    $device = parseUa('');

    expect($device['browser'])->toBe('Unknown')
        ->and($device['browserMajor'])->toBe(0)
        ->and($device['os'])->toBe('Unknown')
        ->and($device['deviceType'])->toBe(DeviceType::Unknown);
});
