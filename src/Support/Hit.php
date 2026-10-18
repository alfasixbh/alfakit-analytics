<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * A page view or custom event on its way in: what the snippet, the middleware or
 * `Analytics::track()` reported. The IP address and user agent only live here, in memory.
 */
final readonly class Hit
{
    /**
     * @param  array<array-key, mixed>  $properties
     */
    public function __construct(
        public string $name,
        public string $url,
        public ?string $referrer = null,
        public array $properties = [],
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?CarbonImmutable $occurredAt = null,
        public ?Request $request = null,
        /** Reported by the application itself, without a browser (no bot check). */
        public bool $isServerSide = false,
    ) {}

    /**
     * A hit reported by (or on behalf of) the browser that made `$request`.
     *
     * @param  array<array-key, mixed>  $properties
     */
    public static function fromRequest(Request $request, string $name, ?string $url = null, ?string $referrer = null, array $properties = []): self
    {
        return new self(
            name: $name,
            url: $url ?? $request->fullUrl(),
            referrer: $referrer,
            properties: $properties,
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            request: $request,
        );
    }

    /**
     * Whether the browser asked not to be tracked (`DNT: 1` or Global Privacy Control).
     */
    public function optedOut(): bool
    {
        return $this->request !== null && ($this->request->header('DNT') === '1' || $this->request->header('Sec-GPC') === '1');
    }
}
