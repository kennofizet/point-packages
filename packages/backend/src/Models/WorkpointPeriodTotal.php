<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Models;

use Illuminate\Database\Eloquent\Builder;
use Kennofizet\Workpoint\Core\Model\BaseModel;

/**
 * Pre-aggregated totals per user per period for fast "top by period" queries.
 * Synced on each WorkpointRecord creation when workpoint.use_period_totals_table is true.
 */
class WorkpointPeriodTotal extends BaseModel
{
    protected $table;

    protected $fillable = [
        'zone_id',
        'user_id',
        'subject_type',
        'subject_id',
        'period_type',
        'period_key',
        'total_points',
    ];

    protected $hidden = [
        'user_id',
        'subject_type',
        'subject_id',
        'period_type',
        'period_key'
    ];

    protected $casts = [
        'user_id' => 'integer',
        'total_points' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        $this->table = self::getTableName();
        parent::__construct($attributes);
    }

    public static function getTableName(): string
    {
        return config('workpoint.period_totals_table', 'workpoint_period_totals');
    }

    protected static function booted(): void
    {
        static::addGlobalScope('workpoint_user_id_not_null_scope', static function (Builder $builder): void {
            $builder->whereNotNull('user_id');
        });
    }
}
