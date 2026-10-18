<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

/**
 * Where a visit came from: the source (`Google`, `Newsletter`, `news.example.com`), its
 * channel (search, social, email, paid, referral, AI assistants, direct) and the referring
 * page without its query string. UTM parameters win over the `Referer` header.
 */
final readonly class Referrer
{
    public const DIRECT = 'direct';

    public const ORGANIC_SEARCH = 'organic_search';

    public const PAID = 'paid';

    public const SOCIAL = 'social';

    public const EMAIL = 'email';

    public const REFERRAL = 'referral';

    public const AI = 'ai';

    public const CAMPAIGN = 'campaign';

    /** @var array<int, string> */
    public const CHANNELS = [self::DIRECT, self::ORGANIC_SEARCH, self::PAID, self::SOCIAL, self::EMAIL, self::REFERRAL, self::AI, self::CAMPAIGN];

    /**
     * Known referring hosts (without `www.`), matched exactly or as a parent domain.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const KNOWN_HOSTS = [
        // AI assistants (before search engines: gemini.google.com is not Google search)
        'chatgpt.com' => ['ChatGPT', self::AI],
        'chat.openai.com' => ['ChatGPT', self::AI],
        'perplexity.ai' => ['Perplexity', self::AI],
        'claude.ai' => ['Claude', self::AI],
        'gemini.google.com' => ['Gemini', self::AI],
        'copilot.microsoft.com' => ['Copilot', self::AI],
        // Email
        'mail.google.com' => ['Gmail', self::EMAIL],
        'outlook.live.com' => ['Outlook', self::EMAIL],
        'outlook.office.com' => ['Outlook', self::EMAIL],
        'outlook.office365.com' => ['Outlook', self::EMAIL],
        'mail.yahoo.com' => ['Yahoo Mail', self::EMAIL],
        'mail.proton.me' => ['Proton Mail', self::EMAIL],
        // Search
        'bing.com' => ['Bing', self::ORGANIC_SEARCH],
        'duckduckgo.com' => ['DuckDuckGo', self::ORGANIC_SEARCH],
        'search.yahoo.com' => ['Yahoo!', self::ORGANIC_SEARCH],
        'yahoo.com' => ['Yahoo!', self::ORGANIC_SEARCH],
        'baidu.com' => ['Baidu', self::ORGANIC_SEARCH],
        'yandex.ru' => ['Yandex', self::ORGANIC_SEARCH],
        'yandex.com' => ['Yandex', self::ORGANIC_SEARCH],
        'ya.ru' => ['Yandex', self::ORGANIC_SEARCH],
        'ecosia.org' => ['Ecosia', self::ORGANIC_SEARCH],
        'search.brave.com' => ['Brave Search', self::ORGANIC_SEARCH],
        'startpage.com' => ['Startpage', self::ORGANIC_SEARCH],
        'qwant.com' => ['Qwant', self::ORGANIC_SEARCH],
        'naver.com' => ['Naver', self::ORGANIC_SEARCH],
        'seznam.cz' => ['Seznam', self::ORGANIC_SEARCH],
        'kagi.com' => ['Kagi', self::ORGANIC_SEARCH],
        // Social
        'facebook.com' => ['Facebook', self::SOCIAL],
        'fb.me' => ['Facebook', self::SOCIAL],
        'instagram.com' => ['Instagram', self::SOCIAL],
        't.co' => ['X', self::SOCIAL],
        'twitter.com' => ['X', self::SOCIAL],
        'x.com' => ['X', self::SOCIAL],
        'linkedin.com' => ['LinkedIn', self::SOCIAL],
        'lnkd.in' => ['LinkedIn', self::SOCIAL],
        'reddit.com' => ['Reddit', self::SOCIAL],
        'news.ycombinator.com' => ['Hacker News', self::SOCIAL],
        'youtube.com' => ['YouTube', self::SOCIAL],
        'youtu.be' => ['YouTube', self::SOCIAL],
        'pinterest.com' => ['Pinterest', self::SOCIAL],
        'tiktok.com' => ['TikTok', self::SOCIAL],
        'threads.net' => ['Threads', self::SOCIAL],
        'bsky.app' => ['Bluesky', self::SOCIAL],
        'mastodon.social' => ['Mastodon', self::SOCIAL],
        'discord.com' => ['Discord', self::SOCIAL],
        't.me' => ['Telegram', self::SOCIAL],
        'web.telegram.org' => ['Telegram', self::SOCIAL],
        'wa.me' => ['WhatsApp', self::SOCIAL],
        'web.whatsapp.com' => ['WhatsApp', self::SOCIAL],
        'vk.com' => ['VK', self::SOCIAL],
        'producthunt.com' => ['Product Hunt', self::SOCIAL],
        // Referral sites with a well-known name
        'github.com' => ['GitHub', self::REFERRAL],
        'stackoverflow.com' => ['Stack Overflow', self::REFERRAL],
        'medium.com' => ['Medium', self::REFERRAL],
        'dev.to' => ['DEV', self::REFERRAL],
    ];

    /**
     * `utm_source` values (lowercase) spelled like the referrer names, so a tagged link and
     * a plain referral from the same place land in the same row.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const KNOWN_UTM_SOURCES = [
        'google' => ['Google', self::ORGANIC_SEARCH],
        'bing' => ['Bing', self::ORGANIC_SEARCH],
        'duckduckgo' => ['DuckDuckGo', self::ORGANIC_SEARCH],
        'facebook' => ['Facebook', self::SOCIAL],
        'fb' => ['Facebook', self::SOCIAL],
        'instagram' => ['Instagram', self::SOCIAL],
        'ig' => ['Instagram', self::SOCIAL],
        'twitter' => ['X', self::SOCIAL],
        'x' => ['X', self::SOCIAL],
        'linkedin' => ['LinkedIn', self::SOCIAL],
        'reddit' => ['Reddit', self::SOCIAL],
        'youtube' => ['YouTube', self::SOCIAL],
        'tiktok' => ['TikTok', self::SOCIAL],
        'hackernews' => ['Hacker News', self::SOCIAL],
        'hn' => ['Hacker News', self::SOCIAL],
        'chatgpt' => ['ChatGPT', self::AI],
        'chatgpt.com' => ['ChatGPT', self::AI],
        'perplexity' => ['Perplexity', self::AI],
        'newsletter' => ['Newsletter', self::EMAIL],
        'email' => ['Email', self::EMAIL],
    ];

    /** @var array<int, string> */
    public const PAID_MEDIUMS = ['cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search', 'paidsocial', 'paid_social', 'paid-social', 'ads', 'ad', 'display', 'cpm', 'cpv', 'banner', 'retargeting'];

    /** @var array<int, string> */
    public const EMAIL_MEDIUMS = ['email', 'e-mail', 'newsletter', 'mail'];

    /** @var array<int, string> */
    public const SOCIAL_MEDIUMS = ['social', 'social-network', 'social_network', 'social-media', 'sm', 'organic_social'];

    public function __construct(
        public ?string $source,
        public string $channel,
        public ?string $referrer,
    ) {}

    /**
     * @param  array<string, string|null>  $utm  `utm_source`, `utm_medium`… of the landing page
     * @param  callable(string): bool|null  $isInternal  Whether a referring host is the site itself
     */
    public static function parse(?string $referrer, array $utm = [], ?callable $isInternal = null): self
    {
        $host = null;
        $page = null;

        if ($referrer !== null && $referrer !== '' && preg_match('#^https?://#i', $referrer) === 1) {
            $parts = parse_url($referrer);
            $host = is_array($parts) && isset($parts['host']) ? strtolower($parts['host']) : null;
            $host = $host !== null && str_starts_with($host, 'www.') ? substr($host, 4) : $host;

            if ($host !== null && $host !== '' && ($isInternal === null || ! $isInternal($host))) {
                $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
                $page = mb_substr($host.($path === '/' ? '' : rtrim($path, '/')), 0, 512);
            } else {
                $host = null;
            }
        }

        $known = $host === null ? null : self::known($host);
        $utmSource = self::clean($utm['utm_source'] ?? null);
        $medium = strtolower((string) self::clean($utm['utm_medium'] ?? null));

        if ($utmSource !== null) {
            $mapped = self::KNOWN_UTM_SOURCES[strtolower($utmSource)] ?? null;
            $source = $mapped[0] ?? $known[0] ?? $utmSource;
            $channel = self::channelFromMedium($medium) ?? $mapped[1] ?? $known[1] ?? ($host !== null ? self::REFERRAL : self::CAMPAIGN);
        } elseif ($host !== null) {
            $source = $known[0] ?? $host;
            $channel = self::channelFromMedium($medium) ?? $known[1] ?? self::REFERRAL;
        } else {
            $source = null;
            $channel = self::channelFromMedium($medium) ?? self::DIRECT;
        }

        return new self($source === null ? null : mb_substr($source, 0, 128), $channel, $page);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    public static function known(string $host): ?array
    {
        $host = strtolower($host);
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
        $host = str_starts_with($host, 'm.') || str_starts_with($host, 'l.') || str_starts_with($host, 'lm.') ? substr($host, (int) strpos($host, '.') + 1) : $host;

        // Search engines on every country domain: google.com, google.co.uk, google.com.bh…
        if (preg_match('/^(?:[a-z0-9-]+\.)*google\.(?:[a-z]{2,3})(?:\.[a-z]{2})?$/', $host) === 1 && ! isset(self::KNOWN_HOSTS[$host])) {
            return ['Google', self::ORGANIC_SEARCH];
        }

        foreach (self::KNOWN_HOSTS as $known => $info) {
            if ($host === $known || str_ends_with($host, '.'.$known)) {
                return $info;
            }
        }

        return null;
    }

    public static function channelFromMedium(string $medium): ?string
    {
        return match (true) {
            $medium === '' => null,
            in_array($medium, self::PAID_MEDIUMS, true) => self::PAID,
            in_array($medium, self::EMAIL_MEDIUMS, true) => self::EMAIL,
            in_array($medium, self::SOCIAL_MEDIUMS, true) => self::SOCIAL,
            in_array($medium, ['organic', 'search'], true) => self::ORGANIC_SEARCH,
            in_array($medium, ['referral', 'link'], true) => self::REFERRAL,
            default => null,
        };
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : mb_substr($value, 0, 128);
    }
}
