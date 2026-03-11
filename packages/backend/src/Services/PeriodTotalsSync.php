<?php declare(strict_types=1);

namespace Kennofizet\Workpoint\Services;

use Kennofizet\Workpoint\Models\WorkpointRecord;
use Kennofizet\Workpoint\Models\WorkpointPeriodTotal;
use Kennofizet\Workpoint\Support\PeriodHelper;

/**
 * Syncs workpoint_period_totals when a record is created so "top by period" can read from the summary table.
 * Package-only; called from WorkpointRecordService after recording.
 */
class PeriodTotalsSync
{
    public function syncRecord(WorkpointRecord $record): void
    {
        $zoneId = $record->zone_id;
        $subjectType = $record->subject_type;
        $subjectId = $record->subject_id;
        $delta = (int) $record->points_delta;
        if ($delta === 0) {
            return;
        }

        foreach (PeriodHelper::PERIODS_ALL as $periodType) {
            $periodKey = PeriodHelper::periodKey($periodType);
            $row = WorkpointPeriodTotal::query()
                ->where('zone_id', $zoneId)
                ->where('subject_type', $subjectType)
                ->where('subject_id', $subjectId)
                ->where('period_type', $periodType)
                ->where('period_key', $periodKey)
                ->first();
            if ($row) {
                $row->increment('total_points', $delta);
            } else {
                WorkpointPeriodTotal::create([
                    'zone_id' => $zoneId,
                    'subject_type' => $subjectType,
                    'subject_id' => $subjectId,
                    'period_type' => $periodType,
                    'period_key' => $periodKey,
                    'total_points' => $delta,
                ]);
            }
        }
    }
}
