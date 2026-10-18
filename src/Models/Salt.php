<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAnalytics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The secret mixed into visitor hashes. Replaced every 24 hours; the previous one is
 * deleted, so yesterday's hashes can't be recomputed or linked to today's.
 *
 * @property int $id
 * @property string $salt
 * @property Carbon $created_at
 */
class Salt extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'alfakit_analytics_salts';

    protected $guarded = ['id'];

    protected $hidden = ['salt'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
