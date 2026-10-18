<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Models;

use AlfaSix\Alfakit\Realtime\BroadcastsToAlfakit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * A visit (session): hits of one visitor less than `session_timeout` minutes apart. The
 * visitor is a 64-bit hash that changes every day, so a visit never links to another day.
 *
 * On `realtime()` panels a visit broadcasts its key (at most every few seconds per site),
 * so the dashboard's "visitors now" card refreshes without polling.
 *
 * @property int $id
 * @property int $site_id
 * @property string $visitor
 * @property string $date Local date (Y-m-d, site timezone)
 * @property Carbon $started_at
 * @property Carbon $last_seen_at
 * @property int $duration
 * @property int $pageviews
 * @property int $events
 * @property bool $is_bounce
 * @property string|null $hostname
 * @property string $entry_page
 * @property string $exit_page
 * @property string|null $source
 * @property string|null $channel
 * @property string|null $referrer
 * @property string|null $utm_source
 * @property string|null $utm_medium
 * @property string|null $utm_campaign
 * @property string|null $utm_content
 * @property string|null $utm_term
 * @property string|null $country
 * @property string|null $device
 * @property string|null $browser
 * @property string|null $os
 */
class Visit extends Model
{
    use BroadcastsToAlfakit;

    /** Seconds between two broadcasts of one site's visits. */
    public const BROADCAST_THROTTLE = 5;

    public $timestamps = false;

    protected $table = 'alfakit_analytics_visits';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'duration' => 'integer',
            'pageviews' => 'integer',
            'events' => 'integer',
            'is_bounce' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function hits(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function shouldBroadcastToAlfakit(string $event): bool
    {
        if ($event === 'deleted') {
            return false;
        }

        try {
            $broadcasting = false;

            foreach (alfakit()->getPanels() as $panel) {
                $broadcasting = $broadcasting || $panel->isBroadcastingModelChanges();
            }

            return $broadcasting && Cache::add('alfakit-analytics:broadcast:'.$this->site_id, true, self::BROADCAST_THROTTLE);
        } catch (Throwable) {
            return false;
        }
    }
}
