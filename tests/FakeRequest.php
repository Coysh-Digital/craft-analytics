<?php

namespace coyshdigital\craftanalytics\tests;

use craft\web\Request;
use yii\web\Cookie;
use yii\web\CookieCollection;
use yii\web\HeaderCollection;

/**
 * A real `craft\web\Request` with its headers and cookies supplied directly.
 *
 * Built without the constructor because Craft's Request parses paths and
 * reads general config on init, none of which the consent state machine
 * touches — it reads headers and cookies and nothing else. Keeping the real
 * type means the code under test keeps its type hints rather than loosening
 * them to suit a test.
 */
class FakeRequest extends Request
{
    private HeaderCollection $fakeHeaders;
    private CookieCollection $fakeCookies;
    private bool $secure = true;

    /**
     * @param array<string,string> $headers
     * @param array<string,string> $cookies
     */
    public static function make(array $headers = [], array $cookies = [], bool $secure = true): self
    {
        /** @var self $request */
        $request = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();

        $headerCollection = new HeaderCollection();
        foreach ($headers as $name => $value) {
            $headerCollection->set($name, $value);
        }

        $cookieObjects = [];
        foreach ($cookies as $name => $value) {
            $cookieObjects[$name] = new Cookie(['name' => $name, 'value' => $value]);
        }

        $request->fakeHeaders = $headerCollection;
        $request->fakeCookies = new CookieCollection($cookieObjects, ['readOnly' => true]);
        $request->secure = $secure;

        return $request;
    }

    public function getHeaders(): HeaderCollection
    {
        return $this->fakeHeaders;
    }

    public function getCookies(): CookieCollection
    {
        return $this->fakeCookies;
    }

    public function getIsSecureConnection(): bool
    {
        return $this->secure;
    }
}
