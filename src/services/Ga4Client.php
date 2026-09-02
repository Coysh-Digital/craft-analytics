<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\enums\Ga4Dataset;
use coyshdigital\craftanalytics\models\Settings;
use coyshdigital\craftanalytics\Plugin;
use Craft;
use craft\helpers\App;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use yii\base\Component;

/**
 * Talks to Google, and only when asked.
 *
 * Every method here is reached from an operator action (connecting, listing
 * properties) or from the import job the operator started - never from a
 * setting, a schedule or a page render. That is the same stance the geo
 * database takes (C7): the plugin makes no outbound call on its own initiative.
 *
 * The read scope is `analytics.readonly`: it can list your properties and read
 * their reports, and it can do nothing else. Nothing about your visitors is
 * ever sent; this only reads what Google already holds.
 */
class Ga4Client extends Component
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v2/userinfo';
    private const ADMIN_ENDPOINT = 'https://analyticsadmin.googleapis.com/v1beta';
    private const DATA_ENDPOINT = 'https://analyticsdata.googleapis.com/v1beta';

    private const SCOPES = [
        'https://www.googleapis.com/auth/analytics.readonly',
        'https://www.googleapis.com/auth/userinfo.email',
    ];

    /** The largest page GA4 will return from a single runReport call. */
    public const REPORT_PAGE_SIZE = 100000;

    public ?Settings $settings = null;
    public ?Ga4AuthStore $auth = null;

    private ?Client $http = null;

    /**
     * Whether both halves of the OAuth client are configured. Without them,
     * there is nothing to connect with and the wizard says so.
     */
    public function hasCredentials(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    /**
     * The consent URL to send an operator to.
     *
     * `access_type=offline` and `prompt=consent` are what make Google return a
     * refresh token: without them a reconnection yields only a short-lived
     * access token, and the import dies an hour in.
     */
    public function authUrl(string $redirectUri, string $state): string
    {
        return self::AUTH_ENDPOINT . '?' . http_build_query([
            'client_id' => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    /**
     * Exchanges the one-time code Google redirected back with for tokens, and
     * stores them.
     */
    public function connect(string $code, string $redirectUri): void
    {
        $tokens = $this->post(self::TOKEN_ENDPOINT, [
            'code' => $code,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        $refresh = (string)($tokens['refresh_token'] ?? '');
        $access = (string)($tokens['access_token'] ?? '');

        if ($refresh === '' || $access === '') {
            throw new Ga4Exception(Craft::t(
                'craft-analytics',
                'Google did not return a refresh token. Disconnect, then connect again and approve the access.',
            ));
        }

        $this->auth()->saveConnection(
            $refresh,
            $access,
            (int)($tokens['expires_in'] ?? 3600),
            $this->email($access),
        );
    }

    /**
     * A valid access token, refreshed from the stored refresh token when the
     * current one has expired.
     */
    public function accessToken(): string
    {
        $store = $this->auth();
        $token = $store->accessToken();
        $expiry = $store->accessTokenExpiry();

        if ($token !== null && $expiry !== null && $expiry > time()) {
            return $token;
        }

        $refresh = $store->refreshToken();

        if ($refresh === null) {
            throw new Ga4Exception(Craft::t('craft-analytics', 'Not connected to Google.'));
        }

        $tokens = $this->post(self::TOKEN_ENDPOINT, [
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'refresh_token' => $refresh,
            'grant_type' => 'refresh_token',
        ]);

        $access = (string)($tokens['access_token'] ?? '');

        if ($access === '') {
            throw new Ga4Exception(Craft::t(
                'craft-analytics',
                'Google would not renew the connection. Disconnect and connect again.',
            ));
        }

        $store->updateAccessToken($access, (int)($tokens['expires_in'] ?? 3600));

        return $access;
    }

    /**
     * The GA4 properties this account can read, ready for a picker.
     *
     * @return array<int,array{id: string, name: string, account: string}>
     */
    public function properties(): array
    {
        $data = $this->get(self::ADMIN_ENDPOINT . '/accountSummaries', ['pageSize' => 200]);

        $properties = [];

        foreach ((array)($data['accountSummaries'] ?? []) as $summary) {
            if (!is_array($summary)) {
                continue;
            }

            $account = (string)($summary['displayName'] ?? '');

            foreach ((array)($summary['propertySummaries'] ?? []) as $property) {
                if (!is_array($property)) {
                    continue;
                }

                $id = (string)($property['property'] ?? '');

                if ($id === '') {
                    continue;
                }

                $properties[] = [
                    'id' => $id,
                    'name' => (string)($property['displayName'] ?? $id),
                    'account' => $account,
                ];
            }
        }

        return $properties;
    }

    /**
     * One page of a GA4 report.
     *
     * @return array<string,mixed> the decoded runReport response
     */
    public function runReport(
        string $propertyId,
        Ga4Dataset $dataset,
        string $startDate,
        string $endDate,
        int $limit = self::REPORT_PAGE_SIZE,
        int $offset = 0,
    ): array {
        return $this->post(self::DATA_ENDPOINT . '/' . $propertyId . ':runReport', [
            'dateRanges' => [['startDate' => $startDate, 'endDate' => $endDate]],
            'dimensions' => array_map(static fn(string $n): array => ['name' => $n], $dataset->dimensions()),
            'metrics' => array_map(static fn(string $n): array => ['name' => $n], $dataset->metrics()),
            'limit' => $limit,
            'offset' => $offset,
            'returnPropertyQuota' => false,
        ], json: true);
    }

    private function email(string $accessToken): ?string
    {
        try {
            $data = $this->get(self::USERINFO_ENDPOINT, [], $accessToken);
            $email = (string)($data['email'] ?? '');

            return $email !== '' ? $email : null;
        } catch (Ga4Exception) {
            // A cosmetic label. Never worth failing a connection over.
            return null;
        }
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function get(string $url, array $query = [], ?string $accessToken = null): array
    {
        try {
            $response = $this->http()->get($url, [
                'headers' => ['Authorization' => 'Bearer ' . ($accessToken ?? $this->accessToken())],
                'query' => $query,
            ]);
        } catch (BadResponseException $e) {
            throw new Ga4Exception($this->readError($e), 0, $e);
        } catch (GuzzleException $e) {
            throw new Ga4Exception(Craft::t('craft-analytics', 'Could not reach Google: {message}', ['message' => $e->getMessage()]), 0, $e);
        }

        return $this->decode((string)$response->getBody());
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function post(string $url, array $body, bool $json = false): array
    {
        $isTokenCall = $url === self::TOKEN_ENDPOINT;
        $options = $json
            ? ['headers' => ['Authorization' => 'Bearer ' . $this->accessToken()], 'json' => $body]
            // The token endpoint is the one call that authenticates with the
            // client secret in the form body rather than a bearer token.
            : ['form_params' => $body];

        try {
            $response = $this->http()->post($url, $options);
        } catch (BadResponseException $e) {
            throw new Ga4Exception($this->readError($e), 0, $e);
        } catch (GuzzleException $e) {
            throw new Ga4Exception(Craft::t('craft-analytics', 'Could not reach Google: {message}', ['message' => $e->getMessage()]), 0, $e);
        }

        return $this->decode((string)$response->getBody());
    }

    /**
     * @return array<string,mixed>
     */
    private function decode(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $data = json_decode($body, true);

        return is_array($data) ? $data : [];
    }

    private function readError(BadResponseException $e): string
    {
        $body = (string)$e->getResponse()->getBody();
        $data = json_decode($body, true);

        if (is_array($data)) {
            // Both the OAuth endpoint and the API report their errors, in two
            // different shapes.
            $message = $data['error']['message'] ?? $data['error_description'] ?? $data['error'] ?? null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return Craft::t('craft-analytics', 'Google returned an error ({status}).', [
            'status' => $e->getResponse()->getStatusCode(),
        ]);
    }

    private function clientId(): string
    {
        return trim((string)App::parseEnv($this->settings()->ga4ClientId ?? ''));
    }

    private function clientSecret(): string
    {
        return trim((string)App::parseEnv($this->settings()->ga4ClientSecret ?? ''));
    }

    private function http(): Client
    {
        return $this->http ??= Craft::createGuzzleClient(['timeout' => 60]);
    }

    private function auth(): Ga4AuthStore
    {
        return $this->auth ??= Plugin::getInstance()->getGa4Auth();
    }

    private function settings(): Settings
    {
        return $this->settings ??= Plugin::getInstance()->getSettings();
    }
}
