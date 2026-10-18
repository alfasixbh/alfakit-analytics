<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A row of a top list as it was when the user exported it. The exporter reads these
 * snapshots (it exports models by key); they are pruned after a day.
 *
 * @property int $id
 * @property string $report
 * @property int $site_id
 * @property int $position
 * @property string $dimension
 * @property string $label
 * @property int $visitors
 * @property int $visits
 * @property int $pageviews
 * @property int $events
 * @property float|null $bounce_rate
 * @property int|null $visit_duration
 * @property float|null $conversion_rate
 * @property float|null $change
 * @property Carbon|null $created_at
 */
class ReportRow extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'alfakit_analytics_report_rows';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'visitors' => 'integer',
            'visits' => 'integer',
            'pageviews' => 'integer',
            'events' => 'integer',
            'bounce_rate' => 'float',
            'visit_duration' => 'integer',
            'conversion_rate' => 'float',
            'change' => 'float',
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
