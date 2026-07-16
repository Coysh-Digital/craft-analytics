<?php

namespace coyshdigital\craftanalytics\services;

use coyshdigital\craftanalytics\enums\Channel;
use coyshdigital\craftanalytics\events\RegisterChannelRulesEvent;
use yii\base\Component;

/**
 * Buckets a referrer into a traffic channel.
 *
 * Host-suffix matching, deliberately: `google.com`, `www.google.co.uk` and
 * `news.google.de` are all search, and enumerating every regional domain of
 * every engine is a losing game. Sites can add their own rules via
 * EVENT_REGISTER_CHANNEL_RULES rather than waiting for us.
 */
class ChannelClassifier extends Component
{
    /**
     * @event RegisterChannelRulesEvent Adds or overrides host => channel rules.
     */
    public const EVENT_REGISTER_CHANNEL_RULES = 'registerChannelRules';

    private const SEARCH_HOSTS = [
        'google.', 'bing.com', 'duckduckgo.com', 'search.yahoo.', 'yandex.',
        'baidu.com', 'ecosia.org', 'startpage.com', 'brave.com', 'qwant.com',
        'search.marginalia.nu', 'searx.', 'lite.duckduckgo.com', 'perplexity.ai',
    ];

    private const SOCIAL_HOSTS = [
        'facebook.com', 'fb.com', 'instagram.com', 'twitter.com', 'x.com', 't.co',
        'linkedin.com', 'lnkd.in', 'reddit.com', 'out.reddit.com', 'pinterest.',
        'youtube.com', 'youtu.be', 'tiktok.com', 'threads.net', 'threads.com',
        'mastodon.', 'bsky.app', 'bsky.social', 'tumblr.com', 'quora.com',
        'whatsapp.com', 'telegram.org', 't.me', 'discord.com', 'news.ycombinator.com',
    ];

    /** @var array<string,Channel>|null host fragment => channel */
    private ?array $rules = null;

    /**
     * @param string $referrer the external referrer ('' for direct)
     * @param bool $hasCampaign whether the URL carried campaign parameters
     */
    public function classify(string $referrer, bool $hasCampaign = false): Channel
    {
        // An explicit campaign tag is the site owner telling us where this
        // came from; it outranks whatever the referrer header says.
        if ($hasCampaign) {
            return Channel::Campaign;
        }

        if ($referrer === '') {
            return Channel::Direct;
        }

        $host = self::host($referrer);

        if ($host === null) {
            return Channel::Direct;
        }

        foreach ($this->rules() as $fragment => $channel) {
            if (self::matches($host, $fragment)) {
                return $channel;
            }
        }

        return Channel::Referral;
    }

    /**
     * The referrer's host, used as the dimension value on the rollup. Only
     * the host — never the full referrer URL, which can carry search terms or
     * other personal detail we have no business storing.
     */
    public static function host(string $referrer): ?string
    {
        $host = parse_url($referrer, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return null;
        }

        return strtolower(ltrim($host, '.'));
    }

    /**
     * @return array<string,Channel>
     */
    private function rules(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $rules = [];

        foreach (self::SEARCH_HOSTS as $host) {
            $rules[$host] = Channel::Search;
        }

        foreach (self::SOCIAL_HOSTS as $host) {
            $rules[$host] = Channel::Social;
        }

        $event = new RegisterChannelRulesEvent(['rules' => $rules]);
        $this->trigger(self::EVENT_REGISTER_CHANNEL_RULES, $event);

        return $this->rules = $event->rules;
    }

    /**
     * A fragment matches the host itself, or the host as a subdomain of it.
     * Trailing-dot fragments ('google.') match any TLD.
     */
    private static function matches(string $host, string $fragment): bool
    {
        if (str_ends_with($fragment, '.')) {
            return $host === rtrim($fragment, '.')
                || str_starts_with($host, $fragment)
                || str_contains($host, '.' . $fragment);
        }

        return $host === $fragment || str_ends_with($host, '.' . $fragment);
    }
}
