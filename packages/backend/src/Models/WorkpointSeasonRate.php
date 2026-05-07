<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Models;

use Kennofizet\Workpoint\Core\Model\BaseModel;

class WorkpointSeasonRate extends BaseModel
{
    protected $fillable = [
        'zone_id',
        'season_id',
        'rate_convert',
    ];

    protected $casts = [
        'zone_id' => 'integer',
        'season_id' => 'integer',
        'rate_convert' => 'float',
    ];

    public static function getTableName(): string
    {
        return config('workpoint.season_rates_table', 'workpoint_season_rates');
    }

    public function getTable(): string
    {
        return self::getTableName();
    }
}
