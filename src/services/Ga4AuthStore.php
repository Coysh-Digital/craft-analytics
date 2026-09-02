<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\db\Table;
use Craft;
use craft\db\Connection;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use yii\base\Component;
use yii\db\Query;

/**
 * The single-row store for the GA4 import's Google connection.
 *
 * A site imports from one Google account and one property, so there is exactly
 * one row, ever. The OAuth tokens are the sensitive part - the refresh token in
 * particular is long-lived - so they are encrypted with Craft's security key on
 * the way in and never leave this class except to the GA4 client. Nothing here
 * touches project config: tokens are secrets, and secrets do not belong in a
 * file that deploys with the site.
 */
class Ga4AuthStore extends Component
{
    public ?Connection $db = null;

    /**
     * The row as it stands, or null when nothing is connected.
     *
     * @return array<string,mixed>|null
     */
    private function row(): ?array
    {
        $row = (new Query())->from(Table::GA4_AUTH)->one($this->db());

        return is_array($row) ? $row : null;
    }

    public function isConnected(): bool
    {
        return $this->refreshToken() !== null;
    }

    /**
     * A view safe to hand a template: who is connected and to what, never the
     * tokens.
     *
     * @return array<string,mixed>
     */
    public function state(): array
    {
        $row = $this->row();

        if ($row === null || $this->refreshToken() === null) {
            return ['connected' => false];
        }

        return [
            'connected' => true,
            'googleEmail' => $row['googleEmail'] ?? null,
            'propertyId' => $row['propertyId'] ?? null,
            'propertyName' => $row['propertyName'] ?? null,
            'siteId' => isset($row['siteId']) ? (int)$row['siteId'] : null,
            'connectedAt' => $row['connectedAt'] ?? null,
        ];
    }

    public function refreshToken(): ?string
    {
        return $this->decrypt($this->row()['refreshToken'] ?? null);
    }

    public function accessToken(): ?string
    {
        return $this->decrypt($this->row()['accessToken'] ?? null);
    }

    /**
     * When the stored access token expires, as a unix timestamp, or null.
     */
    public function accessTokenExpiry(): ?int
    {
        $value = $this->row()['accessTokenExpires'] ?? null;

        if (!is_string($value) || $value === '') {
            return null;
        }

        // DB datetimes are stored in UTC; parse them as such rather than in the
        // server's timezone, or an hour-long token could read expired or valid
        // by the offset between them.
        $date = DateTimeHelper::toDateTime($value, true);

        return $date === false ? null : $date->getTimestamp();
    }

    public function propertyId(): ?string
    {
        $id = $this->row()['propertyId'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    public function siteId(): ?int
    {
        $id = $this->row()['siteId'] ?? null;

        return $id === null ? null : (int)$id;
    }

    /**
     * Records a fresh connection, replacing any previous one.
     *
     * The whole row is rewritten: connecting a different account should not
     * leave the old account's property selected behind it.
     */
    public function saveConnection(string $refreshToken, string $accessToken, int $expiresIn, ?string $email): void
    {
        $this->replace([
            'refreshToken' => $this->encrypt($refreshToken),
            'accessToken' => $this->encrypt($accessToken),
            'accessTokenExpires' => $this->expiryFor($expiresIn),
            'googleEmail' => $email,
            'propertyId' => null,
            'propertyName' => null,
            'siteId' => null,
            'connectedAt' => Db::prepareDateForDb(new \DateTime()),
        ]);
    }

    public function updateAccessToken(string $accessToken, int $expiresIn): void
    {
        $id = $this->id();

        if ($id === null) {
            return;
        }

        Db::update(Table::GA4_AUTH, [
            'accessToken' => $this->encrypt($accessToken),
            'accessTokenExpires' => $this->expiryFor($expiresIn),
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ], ['id' => $id], [], true, $this->db());
    }

    public function saveProperty(string $propertyId, string $propertyName, int $siteId): void
    {
        $id = $this->id();

        if ($id === null) {
            return;
        }

        Db::update(Table::GA4_AUTH, [
            'propertyId' => $propertyId,
            'propertyName' => $propertyName,
            'siteId' => $siteId,
            'dateUpdated' => Db::prepareDateForDb(new \DateTime()),
        ], ['id' => $id], [], true, $this->db());
    }

    /**
     * Forgets the connection and its tokens entirely.
     */
    public function disconnect(): void
    {
        Db::delete(Table::GA4_AUTH, [], [], $this->db());
    }

    /**
     * @param array<string,mixed> $values
     */
    private function replace(array $values): void
    {
        $this->disconnect();

        $now = Db::prepareDateForDb(new \DateTime());
        $this->db()->createCommand()
            ->insert(Table::GA4_AUTH, $values + ['dateCreated' => $now, 'dateUpdated' => $now])
            ->execute();
    }

    private function id(): ?int
    {
        $id = (new Query())->select('id')->from(Table::GA4_AUTH)->scalar($this->db());

        return $id === false ? null : (int)$id;
    }

    private function expiryFor(int $expiresIn): string
    {
        // A minute of slack, so a token is treated as expired slightly before
        // Google actually rejects it rather than slightly after.
        $seconds = max(0, $expiresIn - 60);

        return (string)Db::prepareDateForDb(new \DateTime('@' . (time() + $seconds)));
    }

    private function encrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Craft::$app->getSecurity()->encryptByKey($value);
    }

    private function decrypt(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $plain = Craft::$app->getSecurity()->decryptByKey($value);

        return $plain === false ? null : $plain;
    }

    private function db(): Connection
    {
        return $this->db ??= Craft::$app->getDb();
    }
}
