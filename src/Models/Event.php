<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A page view (`name` = `pageview`) or a custom event, at a path without its query string.
 *
 * @property int $id
 * @property int $site_id
 * @property int $visit_id
 * @property string $visitor
 * @property string $name
 * @property string $date Local date (Y-m-d, site timezone)
 * @property int $hour
 * @property Carbon $occurred_at
 * @property string $pathname
 * @property string|null $hostname
 */
class Event extends Model
{
    public const PAGEVIEW = 'pageview';

    public $timestamps = false;

    protected $table = 'alfakit_analytics_events';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'hour' => 'integer',
            'occurred_at' => 'datetime',
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
     * @return BelongsTo<Visit, $this>
     */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /**
     * @return HasMany<EventProperty, $this>
     */
    public function properties(): HasMany
    {
        return $this->hasMany(EventProperty::class);
    }
}
