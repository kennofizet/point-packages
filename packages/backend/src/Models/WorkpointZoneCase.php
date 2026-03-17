<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Models;

use Illuminate\Database\Eloquent\Model;

class WorkpointZoneCase extends Model
{
    protected $fillable = [
        'zone_id',
        'case_key',
        'points',
        'check',
        'period',
        'cap',
        'descriptions',
    ];

    protected $casts = [
        'points' => 'integer',
        'cap' => 'integer',
        'descriptions' => 'array',
    ];

    public static function getTableName(): string
    {
        return config('workpoint.zone_cases_table', 'workpoint_zone_cases');
    }

    public function getTable(): string
    {
        return self::getTableName();
    }
}
