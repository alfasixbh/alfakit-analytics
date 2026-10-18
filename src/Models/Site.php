<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Models;

use AlfaSix\AlfakitAnalytics\Support\Goal;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * A website (or app) whose visits are counted. The snippet identifies it by its public
 * `key`; hits from hostnames other than `domain` / `domains` are dropped.
 *
 * @property int $id
 * @property string|null $tenant_type
 * @property string|null $tenant_id
 * @property string $name
 * @property string|null $domain
 * @property array<int, string>|null $domains
 * @property string $key
 * @property string $timezone
 * @property bool $is_active
 * @property array<int, array{name?: string, type?: string, match?: string}>|null $goals
 * @property bool $is_shared
 * @property string|null $share_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Site extends Model
{
    protected $table = 'alfakit_analytics_sites';

    protected $fillable = ['name', 'domain', 'domains', 'timezone', 'is_active', 'goals', 'is_shared'];

    protected $hidden = ['share_token'];

    protected $attributes = [
        'timezone' => 'UTC',
        'is_active' => true,
        'is_shared' => false,
    ];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'goals' => 'array',
            'is_active' => 'boolean',
            'is_shared' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (self $site): void {
            if (blank($site->getAttribute('key'))) {
                $site->setAttribute('key', static::generateKey());
            }

            if (blank($site->getAttribute('share_token'))) {
                $site->setAttribute('share_token', Str::random(40));
            }
        });

        static::saving(static function (self $site): void {
            $site->setAttribute('domain', static::normalizeHostname($site->domain));
        });
    }

    public static function generateKey(): string
    {
        do {
            $key = strtolower(Str::random(20));
        } while (static::query()->where('key', $key)->exists());

        return $key;
    }

    /**
     * `https://www.Example.com/path` → `example.com` (`www.` is dropped; `*.` is kept).
     */
    public static function normalizeHostname(?string $host): ?string
    {
        if ($host === null || trim($host) === '') {
            return null;
        }

        $host = strtolower(trim($host));

        if (str_contains($host, '://')) {
            $host = (string) parse_url($host, PHP_URL_HOST);
        }

        $host = (string) Str::of($host)->before('/')->before(':')->trim('.');

        return $host === '' ? null : (str_starts_with($host, 'www.') ? substr($host, 4) : $host);
    }

    /**
     * @return HasMany<Visit, $this>
     */
    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    /**
     * @return HasMany<DailyStat, $this>
     */
    public function dailyStats(): HasMany
    {
        return $this->hasMany(DailyStat::class);
    }

    // ---------------------------------------------------------------- Hostnames

    /**
     * The hostnames hits may come from (normalised). Empty: any hostname.
     *
     * @return array<int, string>
     */
    public function getHostnames(): array
    {
        $hosts = [$this->domain, ...array_values($this->domains ?? [])];

        return array_values(array_unique(array_filter(array_map(static::normalizeHostname(...), $hosts))));
    }

    /**
     * Whether a hit from `$hostname` belongs to the site: an exact match (ignoring `www.`)
     * or a subdomain of a `*.example.com` entry.
     */
    public function allowsHostname(?string $hostname): bool
    {
        $allowed = $this->getHostnames();

        if ($allowed === []) {
            return true;
        }

        $hostname = static::normalizeHostname($hostname);

        if ($hostname === null) {
            return false;
        }

        foreach ($allowed as $host) {
            if ($host === $hostname) {
                return true;
            }

            if (str_starts_with($host, '*.') && (str_ends_with($hostname, substr($host, 1)) || $hostname === substr($host, 2))) {
                return true;
            }
        }

        return false;
    }

    public function getTimezone(): string
    {
        try {
            return (new DateTimeZone($this->timezone))->getName();
        } catch (Throwable) {
            return 'UTC';
        }
    }

    // ---------------------------------------------------------------- Goals

    /**
     * @return array<int, Goal>
     */
    public function getGoals(): array
    {
        $goals = [];

        foreach ($this->goals ?? [] as $goal) {
            if (! is_array($goal)) {
                continue;
            }

            $goal = Goal::fromArray($goal);

            if ($goal !== null) {
                $goals[$goal->name] = $goal;
            }
        }

        return array_values($goals);
    }

    public function getGoal(string $name): ?Goal
    {
        foreach ($this->getGoals() as $goal) {
            if ($goal->name === $name) {
                return $goal;
            }
        }

        return null;
    }

    // ---------------------------------------------------------------- Sharing

    /**
     * Issue a new share token: every shared link handed out before stops working.
     */
    public function rotateShareToken(): static
    {
        $this->forceFill(['share_token' => Str::random(40)])->save();

        return $this;
    }

    // ---------------------------------------------------------------- Tenancy

    public function setTenant(?Model $tenant): static
    {
        $this->setAttribute('tenant_type', $tenant?->getMorphClass());
        $this->setAttribute('tenant_id', $tenant === null ? null : (string) $tenant->getKey());

        return $this;
    }

    public function isOwnedByTenant(?Model $tenant): bool
    {
        if ($tenant === null) {
            return $this->tenant_type === null;
        }

        return $this->tenant_type === $tenant->getMorphClass() && (string) $this->tenant_id === (string) $tenant->getKey();
    }

    /**
     * The sites of this tenant (null: the sites without a tenant).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOwnedBy(Builder $query, ?Model $tenant): Builder
    {
        return static::constrainToTenant($query, $tenant);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function constrainToTenant(Builder $query, ?Model $tenant): Builder
    {
        if ($tenant === null) {
            return $query->whereNull('tenant_type');
        }

        return $query->where('tenant_type', $tenant->getMorphClass())->where('tenant_id', (string) $tenant->getKey());
    }
}
