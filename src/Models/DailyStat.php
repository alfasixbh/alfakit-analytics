<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A daily rollup row: the metrics of one value of one dimension (`source` = `Google`) on
 * one local date. The row with an empty dimension holds the day's totals.
 *
 * @property int $id
 * @property int $site_id
 * @property string $date Local date (Y-m-d, site timezone)
 * @property string $dimension
 * @property string $value
 * @property int $visitors
 * @property int $visits
 * @property int $pageviews
 * @property int $events
 * @property int $bounces
 * @property int $duration
 */
class DailyStat extends Model
{
    public $timestamps = false;

    protected $table = 'alfakit_analytics_daily';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'visitors' => 'integer',
            'visits' => 'integer',
            'pageviews' => 'integer',
            'events' => 'integer',
            'bounces' => 'integer',
            'duration' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
