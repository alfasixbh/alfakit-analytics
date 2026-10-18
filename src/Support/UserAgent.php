<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

/**
 * A small user-agent parser: bot detection, browser, operating system and device type.
 * Only these three labels are kept; the user-agent string itself is never stored.
 */
final readonly class UserAgent
{
    public const DESKTOP = 'desktop';

    public const MOBILE = 'mobile';

    public const TABLET = 'tablet';

    /**
     * Fragments of crawler, monitor, preview and HTTP-library user agents (lowercase).
     *
     * @var array<int, string>
     */
    public const BOT_PATTERNS = [
        'bot', 'crawl', 'spider', 'slurp', 'curl/', 'wget', 'python-', 'python/', 'httpclient', 'http-client',
        'okhttp', 'go-http-client', 'java/', 'libwww', 'scrapy', 'headless', 'phantomjs', 'puppeteer',
        'playwright', 'selenium', 'webdriver', 'lighthouse', 'pagespeed', 'gtmetrix', 'pingdom', 'uptime',
        'monitor', 'facebookexternalhit', 'facebookcatalog', 'embedly', 'preview', 'whatsapp', 'axios/',
        'node-fetch', 'undici', 'postman', 'insomnia', 'httpie', 'feedfetcher', 'validator', 'checker',
        'archive.org', 'ia_archiver', 'mediapartners', 'adsbot', 'apis-google', 'gptbot', 'chatgpt-user',
        'claude-web', 'perplexity', 'ccbot', 'bytespider', 'amazonbot', 'petalbot', 'semrush', 'ahrefs',
        'mj12', 'dotbot', 'censys', 'zgrab', 'masscan', 'nmap', 'guzzlehttp', 'symfony httpclient',
    ];

    public function __construct(
        public bool $isBot,
        public ?string $browser,
        public ?string $os,
        public string $device,
    ) {}

    /**
     * @param  array<int, string>  $extraBotPatterns  Lowercase fragments added to `BOT_PATTERNS`
     */
    public static function parse(?string $userAgent, array $extraBotPatterns = []): self
    {
        $userAgent = trim((string) $userAgent);

        return new self(
            isBot: self::detectBot($userAgent, $extraBotPatterns),
            browser: self::detectBrowser($userAgent),
            os: self::detectOs($userAgent),
            device: self::detectDevice($userAgent),
        );
    }

    /**
     * @param  array<int, string>  $extraBotPatterns
     */
    public static function detectBot(string $userAgent, array $extraBotPatterns = []): bool
    {
        // Real browsers always send a long user agent.
        if (strlen($userAgent) < 20 || ! str_contains($userAgent, '/')) {
            return true;
        }

        $lower = strtolower($userAgent);

        foreach ([...self::BOT_PATTERNS, ...$extraBotPatterns] as $pattern) {
            $pattern = strtolower(trim($pattern));

            if ($pattern !== '' && str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    public static function detectBrowser(string $ua): ?string
    {
        return match (true) {
            str_contains($ua, 'FBAN') || str_contains($ua, 'FBAV') => 'Facebook',
            str_contains($ua, 'Instagram') => 'Instagram',
            str_contains($ua, 'Edg/') || str_contains($ua, 'EdgA/') || str_contains($ua, 'EdgiOS/') || str_contains($ua, 'Edge/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') || str_contains($ua, 'OPT/') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'YaBrowser') => 'Yandex Browser',
            str_contains($ua, 'Vivaldi') => 'Vivaldi',
            str_contains($ua, 'UCBrowser') => 'UC Browser',
            str_contains($ua, 'DuckDuckGo/') => 'DuckDuckGo',
            str_contains($ua, 'FxiOS') || str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome/') || str_contains($ua, 'Chromium/') => 'Chrome',
            str_contains($ua, 'Safari/') && (str_contains($ua, 'Version/') || str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')) => 'Safari',
            str_contains($ua, 'MSIE ') || str_contains($ua, 'Trident/') => 'Internet Explorer',
            default => null,
        };
    }

    public static function detectOs(string $ua): ?string
    {
        return match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'iPod') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'Linux') || str_contains($ua, 'X11') => 'Linux',
            default => null,
        };
    }

    public static function detectDevice(string $ua): string
    {
        $lower = strtolower($ua);

        if (str_contains($lower, 'ipad') || str_contains($lower, 'tablet') || str_contains($lower, 'kindle') || str_contains($lower, 'silk/')
            || (str_contains($lower, 'android') && ! str_contains($lower, 'mobile'))) {
            return self::TABLET;
        }

        if (str_contains($lower, 'mobi') || str_contains($lower, 'iphone') || str_contains($lower, 'ipod') || str_contains($lower, 'android')
            || str_contains($lower, 'windows phone') || str_contains($lower, 'blackberry')) {
            return self::MOBILE;
        }

        return self::DESKTOP;
    }
}
