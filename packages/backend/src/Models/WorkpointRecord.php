<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Models;

use Kennofizet\Workpoint\Core\Model\BaseModel;
use Kennofizet\Workpoint\Support\PeriodHelper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WorkpointRecord extends BaseModel
{
    protected $table;

    protected $fillable = [
        'zone_id',
        'subject_type',
        'subject_id',
        'target_type',
        'target_id',
        'action_key',
        'points_delta',
        'payload',
    ];

    protected $hidden = [
        'subject_type',
        'subject_id',
        'target_type',
        'target_id',
        'action_key',
        'points_delta'
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function __construct(array $attributes = [])
    {
        $this->table = self::getTableName();
        parent::__construct($attributes);
    }

    /**
     * Table name from config (avoids instantiating model when only table name is needed).
     */
    public static function getTableName(): string
    {
        return config('workpoint.table', 'workpoint_records');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }

    public function target(): MorphTo
    {
        return $this->morphTo('target');
    }

    /**
     * Scope to records within the given period (day|week|month|year).
     */
    public function scopeInPeriod(Builder $query, string $period): Builder
    {
        $range = PeriodHelper::range($period);
        return $query->whereBetween($this->getTable() . '.created_at', [$range['start'], $range['end']]);
    }
}
