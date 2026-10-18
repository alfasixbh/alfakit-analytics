<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One property of a custom event (`Analytics::track('signup', ['plan' => 'pro'])`).
 *
 * @property int $id
 * @property int $event_id
 * @property int $site_id
 * @property string $date Local date (Y-m-d, site timezone)
 * @property string $event_name
 * @property string $key
 * @property string $value
 */
class EventProperty extends Model
{
    public $timestamps = false;

    protected $table = 'alfakit_analytics_event_properties';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
