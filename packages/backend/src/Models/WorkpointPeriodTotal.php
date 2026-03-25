<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Models;

use Kennofizet\Workpoint\Core\Model\BaseModel;

/**
 * Pre-aggregated totals per subject per period for fast "top by period" queries.
 * Synced on each WorkpointRecord creation when workpoint.use_period_totals_table is true.
 */
class WorkpointPeriodTotal extends BaseModel
{
    protected $table;

    protected $fillable = [
        'zone_id',
        'subject_type',
        'subject_id',
        'period_type',
        'period_key',
        'total_points',
    ];

    protected $hidden = [
        'subject_type',
        'subject_id',
        'period_type',
        'period_key'
    ];

    protected $casts = [
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
}
